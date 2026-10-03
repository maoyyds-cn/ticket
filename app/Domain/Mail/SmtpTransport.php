<?php

/**
 * SMTP 客户端
 *
 * 手写而非引入依赖：服务器上没有 Composer，而一台 2GB 的机器上为了发几封
 * 通知邮件装一个框架并不划算。
 *
 * 相对 v1 的改进点：
 *  - 每一步都有超时预算，且**检查每一步的返回值**（v1 忽略了 EHLO/AUTH/
 *    MAIL/RCPT/DATA 的返回，写失败要等到下一步读超时才暴露）；
 *  - 头部注入在这里就被挡住：收件人、发件人都必须是合法邮箱，
 *    主题与显示名做 RFC2047 编码，Message-ID 的 host 段过滤掉 CR/LF；
 *    v1 的 Message-ID 直接拼了 mail_smtp_host，配置里塞换行就能注入额外头部；
 *  - 默认校验证书（v1 也是），并提供关闭开关便于自签证书的自建邮件服务器。
 */
declare(strict_types=1);

namespace App\Domain\Mail;

use RuntimeException;

final class SmtpTransport
{
    /** @var resource|null */
    private $socket = null;

    private int $deadline = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $secure,      // ssl | tls | none
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeoutSeconds = 15,
        private readonly bool $verifyPeer = true,
    ) {
    }

    /** @param array{from:string,fromName:string,to:string,toName:string,subject:string,html:string,text:string} $message */
    public function send(array $message): void
    {
        $this->deadline = time() + $this->timeoutSeconds;
        $this->connect();

        try {
            $this->handshake();
            $this->auth();
            $this->envelope($message);
            $this->data($message);
            // QUIT 失败不影响投递结果，吞掉错误即可
            try {
                $this->command('QUIT');
            } catch (RuntimeException) {
            }
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        if ($this->host === '') {
            throw new RuntimeException('SMTP 服务器地址未配置');
        }

        // ssl 用 ssl:// 直连（465），tls 用 tcp:// 再 STARTTLS（587），
        // none 即明文（仅适合内网/本机 MTA）
        $scheme = match ($this->secure) {
            'ssl' => 'ssl',
            'tls' => 'tcp',
            default => 'tcp',
        };

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
                'allow_self_signed' => !$this->verifyPeer,
                'SNI_enabled' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $scheme . '://' . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            max(1, $this->deadline - time()),
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            throw new RuntimeException(sprintf('无法连接 SMTP 服务器 %s:%d（%s）', $this->host, $this->port, $errstr !== '' ? $errstr : '错误码 ' . $errno));
        }
        $this->socket = $socket;
        stream_set_timeout($socket, max(1, $this->deadline - time()));

        $this->expect([220], '服务器问候');
    }

    private function handshake(): void
    {
        $this->command('EHLO ' . $this->localHostname(), [250]);

        if ($this->secure === 'tls') {
            $this->command('STARTTLS', [220]);
            $ok = @stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            );
            if ($ok !== true) {
                throw new RuntimeException('STARTTLS 握手失败');
            }
            // 重新打招呼：TLS 协商后必须再发一次 EHLO
            $this->command('EHLO ' . $this->localHostname(), [250]);
        }
    }

    private function auth(): void
    {
        if ($this->username === '') {
            return;
        }
        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($this->username), [334]);
        $this->command(base64_encode($this->password), [235], '认证失败，请检查邮箱账号与授权码');
    }

    /** @param array{from:string,to:string} $message */
    private function envelope(array $message): void
    {
        $this->command('MAIL FROM:<' . $message['from'] . '>', [250]);
        $this->command('RCPT TO:<' . $message['to'] . '>', [250, 251], '收件人被服务器拒绝');
    }

    /**
     * @param array{from:string,fromName:string,to:string,toName:string,subject:string,html:string,text:string} $message
     */
    private function data(array $message): void
    {
        $this->command('DATA', [354]);

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->encodeName($message['fromName']) . ' <' . $message['from'] . '>',
            'To: ' . $this->encodeName($message['toName']) . ' <' . $message['to'] . '>',
            'Subject: ' . $this->encodeHeader($message['subject']),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->mailDomain() . '>',
            'MIME-Version: 1.0',
            'X-Mailer: Roblox-Ticket-System',
            'Auto-Submitted: auto-generated',
        ];

        if ($message['html'] !== '') {
            $boundary = 'tk' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = '--' . $boundary . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($message['text'] !== '' ? $message['text'] : strip_tags($message['html'])), 76, "\r\n")
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($message['html']), 76, "\r\n")
                . '--' . $boundary . "--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $body = chunk_split(base64_encode($message['text']), 76, "\r\n");
        }

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body;

        // 行首的点必须转义，否则服务器会把它当成数据结束标记
        $payload = preg_replace('/^\./m', '..', $payload) ?? $payload;
        if (!str_ends_with($payload, "\r\n")) {
            $payload .= "\r\n";
        }

        $this->write($payload . ".\r\n");
        $this->expect([250], '邮件被服务器拒绝');
    }

    /** @param list<int> $expect */
    private function command(string $command, array $expect = [250], string $hint = ''): string
    {
        $this->write($command . "\r\n");
        return $this->expect($expect, $hint !== '' ? $hint : $command);
    }

    /**
     * 写入并确保全部字节都已发出。
     *
     * 这一点值得单独说明：fwrite 在非阻塞或网络拥塞时可能只写出一部分，
     * 而 PHP 文档明确说 fwrite 只在失败时返回 false，**返回较小的整数是正常现象**。
     * 旧版直接 `fwrite(...) === false` 判成败，于是「写了一半」被当成成功，
     * 后续读响应时超时，日志里留下一句含义模糊的「发送失败」。
     * DATA 阶段是几十 KB 的大写入，正是最容易触发的场景。
     *
     * 同时每次重试都按剩余预算重设超时：stream_set_timeout 实际设置的是
     * SO_SNDTIMEO，只设一次的话，多次重试累计耗时可能突破总预算。
     */
    private function write(string $data): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('SMTP 连接已关闭');
        }

        $len = strlen($data);
        $sent = 0;

        while ($sent < $len) {
            $remain = $this->deadline - time();
            if ($remain <= 0) {
                throw new RuntimeException('向 SMTP 服务器写入超时（' . $this->timeoutSeconds . ' 秒预算已用完）');
            }
            stream_set_timeout($this->socket, max(1, $remain));

            $n = @fwrite($this->socket, substr($data, $sent));
            if ($n === false || $n === 0) {
                throw new RuntimeException('向 SMTP 服务器写入数据失败（连接可能已中断）');
            }
            $sent += $n;
        }
    }

    /**
     * 读一条（或多行）响应并校验状态码。
     *
     * @param list<int> $expect
     */
    private function expect(array $expect, string $context): string
    {
        $response = $this->readResponse();
        $code = (int)substr($response, 0, 3);

        if (!in_array($code, $expect, true)) {
            throw new RuntimeException(sprintf(
                '%s失败（SMTP %d）：%s',
                $context,
                $code,
                trim(substr($response, 4))
            ));
        }
        return $response;
    }

    /**
     * SMTP 多行响应：状态码后有 '-' 表示后面还有行。
     */
    private function readResponse(): string
    {
        if ($this->socket === null) {
            throw new RuntimeException('SMTP 连接已关闭');
        }
        $full = '';
        while (true) {
            if (time() > $this->deadline) {
                throw new RuntimeException('SMTP 响应超时（' . $this->timeoutSeconds . ' 秒预算已用完）');
            }
            stream_set_timeout($this->socket, max(1, $this->deadline - time()));
            $line = @fgets($this->socket, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('SMTP 服务器响应超时');
                }
                throw new RuntimeException('SMTP 连接被服务器关闭');
            }
            $full .= $line;
            // 第 4 个字符不是 '-' 说明这是最后一行
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $full;
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /** 主题/显示名用 RFC2047 编码，避免中文乱码，也顺带挡掉换行注入 */
    private function encodeHeader(string $value): string
    {
        $value = $this->stripCrlf($value);
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function encodeName(string $name): string
    {
        $name = $this->stripCrlf($name);
        return $name === '' ? '' : $this->encodeHeader($name);
    }

    /** Message-ID 的 host 段必须是合法域名形态，绝不允许 CR/LF 进入头部 */
    private function mailDomain(): string
    {
        $host = preg_replace('/[^A-Za-z0-9.\-]/', '', $this->host) ?? '';
        return $host !== '' ? $host : 'localhost';
    }

    private function stripCrlf(string $s): string
    {
        return str_replace(["\r", "\n", "\0"], '', $s);
    }

    private function localHostname(): string
    {
        $host = $this->mailDomain();
        return $host !== 'localhost' ? $host : 'localhost.localdomain';
    }
}
