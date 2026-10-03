<?php

/**
 * 输入校验
 *
 * v1 的校验散在各个页面里，规则是「一串 if + flash 提示」，同一个字段
 * 在提交工单、注册、后台新增三处的规则还各不相同。这里把规则收成声明式，
 * 字段名 -> 规则串，错误信息统一格式化。
 *
 * 用法：
 *   $v = Validator::make($data, [
 *       'title'   => 'required|length:4,200',
 *       'email'   => 'required|email',
 *       'content' => 'required|min:10',
 *   ], ['title' => '标题']);
 */
declare(strict_types=1);

namespace App\Support;

final class Validator
{
    /** @var array<string,string> 字段 => 第一条错误 */
    private array $errors = [];

    /** @var array<string,mixed> 清洗后的数据 */
    private array $clean = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels 字段中文名
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $labels = [],
    ) {
        $this->run();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? $field;
    }

    private function value(string $field): string
    {
        $v = $this->data[$field] ?? '';
        return is_scalar($v) ? trim((string)$v) : '';
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $raw = $this->value($field);
            $required = str_contains($ruleString, 'required');
            $rules = explode('|', $ruleString);

            // 非必填且为空：跳过后续校验，但仍然写入清洗结果（空串）
            if (!$required && $raw === '') {
                $this->clean[$field] = '';
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === '' || $rule === 'required') {
                    if ($rule === 'required' && $raw === '') {
                        $this->fail($field, $this->label($field) . '不能为空');
                        break;
                    }
                    continue;
                }

                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, '');
                $args = $arg === '' ? [] : explode(',', $arg);

                if (!$this->check($name, $raw, $args)) {
                    $this->fail($field, $this->message($name, $field, $args));
                    break;
                }
            }

            if (!isset($this->errors[$field])) {
                $this->clean[$field] = $this->normalize($field, $raw);
            }
        }
    }

    /** @param list<string> $args */
    private function check(string $name, string $value, array $args): bool
    {
        return match ($name) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'min' => mb_strlen($value, 'UTF-8') >= (int)($args[0] ?? 0),
            'max' => mb_strlen($value, 'UTF-8') <= (int)($args[0] ?? 0),
            'length' => mb_strlen($value, 'UTF-8') >= (int)($args[0] ?? 0)
                && mb_strlen($value, 'UTF-8') <= (int)($args[1] ?? PHP_INT_MAX),
            'int' => preg_match('/^-?\d+$/', $value) === 1,
            'numeric' => is_numeric($value),
            'in' => in_array($value, $args, true),
            'username' => preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,32}$/u', $value) === 1,
            'password' => mb_strlen($value, 'UTF-8') >= (int)($args[0] ?? 6),
            'qq' => preg_match('/^[1-9]\d{4,11}$/', $value) === 1,
            // 只允许站内路径，防止开放重定向
            'safepath' => str_starts_with($value, '/') && !str_starts_with($value, '//'),
            'date' => strtotime($value) !== false,
            default => true,
        };
    }

    /** @param list<string> $args */
    private function message(string $rule, string $field, array $args): string
    {
        $label = $this->label($field);
        return match ($rule) {
            'email' => $label . '格式不正确',
            'min' => $label . '至少需要 ' . ($args[0] ?? '') . ' 个字符',
            'max' => $label . '不能超过 ' . ($args[0] ?? '') . ' 个字符',
            'length' => $label . '长度需在 ' . ($args[0] ?? '') . '–' . ($args[1] ?? '') . ' 个字符之间',
            'int', 'numeric' => $label . '必须是数字',
            'in' => $label . '取值不合法',
            'username' => $label . '只能包含中英文、数字与下划线，长度 2–32',
            'password' => $label . '至少 ' . ($args[0] ?? '6') . ' 位',
            'qq' => $label . '看起来不是有效的 QQ 号',
            'safepath' => $label . '必须是站内地址',
            default => $label . '填写有误',
        };
    }

    /** 字段级格式归一化：邮箱统一小写，避免 a@x.com 与 A@x.com 变成两个账号 */
    private function normalize(string $field, string $value): string
    {
        return match ($field) {
            'email', 'contact_email' => mb_strtolower($value),
            default => $value,
        };
    }

    private function fail(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        return $this->errors === [] ? '' : (string)reset($this->errors);
    }

    /** @return array<string,mixed> 清洗后的数据 */
    public function clean(): array
    {
        return $this->clean;
    }

    public function cleanValue(string $field, string $default = ''): string
    {
        $v = $this->clean[$field] ?? $default;
        return is_scalar($v) ? (string)$v : $default;
    }

    /**
     * 校验并归一化一个 YYYY-MM-DD 日期。
     *
     * 返回空串表示「不是合法日期」，调用方据此当作未填处理。
     *
     * 用 checkdate() 而不是只匹配 `\d{4}-\d{2}-\d{2}`：
     * 正则只管形状，`2024-02-31`、`2024-13-45` 都能通过，
     * 而这类值进到 SQL 里之后，MySQL 8.0.19+ 会直接报
     * 「Incorrect datetime value」让页面 500，5.7 则静默匹配不到任何行——
     * 同一个输入在两种版本上表现完全不同，是最难排查的一类问题。
     * 现在把它挡在应用层，行为与数据库版本无关。
     */
    public static function date(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return '';
        }
        [, $y, $mo, $d] = $m;
        return checkdate((int)$mo, (int)$d, (int)$y) ? $value : '';
    }
}
