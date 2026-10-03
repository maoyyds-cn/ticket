<?php

/**
 * 极简依赖注入容器
 *
 * 只做两件事：按类名缓存单例、按类名自动解析构造参数。
 * 不引入反射缓存之外的任何魔法，避免「东西是从哪来的」变成玄学。
 */
declare(strict_types=1);

namespace App\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class Container
{
    /** @var array<string,object> 已实例化的单例 */
    private array $instances = [];

    /** @var array<string,Closure> 工厂闭包 */
    private array $factories = [];

    /** @var array<string,bool> 正在解析中，用于发现循环依赖 */
    private array $resolving = [];

    /**
     * 构造函数私有化，唯一的创建入口是 fresh()。
     *
     * 为什么不让外部直接 new：Controller 基类的签名是 (Container $container)，
     * 而构造函数无参，因此容器在自动解析时会「成功地」造出一个全新的
     * **空容器**（没有任何工厂），随后解析 View 等依赖时就会以看不懂的方式失败。
     * 这个坑实际发生过：首页 500，报的却是「View 的 $templateDir 无法注入」。
     *
     * 私有化之后，`new Container()` 会直接因可见性报错，
     * 而不是悄悄换成一个不共享任何服务的空容器。
     */
    private function __construct()
    {
    }

    public static function fresh(): self
    {
        /** @var self $c */
        $c = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $c->instances = [];
        $c->factories = [];
        $c->resolving = [];
        return $c;
    }

    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    public function instance(string $id, object $object): void
    {
        $this->instances[$id] = $object;
    }

    /**
     * 取实例：工厂优先，其次是已缓存单例，最后尝试自动解析。
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        return $this->instances[$id] = $this->build($id);
    }

    /**
     * 自动解析：递归注入构造函数中类型为类、且可实例化的参数。
     *
     * 参数带默认值时用默认值；是标量且无默认值时无法解析，直接报错而不是
     * 猜一个值——静默塞 null 会让错误推迟到很远的地方才暴露。
     */
    public function build(string $class): object
    {
        if (isset($this->resolving[$class])) {
            throw new RuntimeException('检测到循环依赖：' . $class);
        }
        if (!class_exists($class)) {
            throw new RuntimeException('无法解析的类：' . $class);
        }

        $ref = new ReflectionClass($class);
        if (!$ref->isInstantiable()) {
            throw new RuntimeException('类不可实例化：' . $class);
        }

        $ctor = $ref->getConstructor();
        if ($ctor === null || $ctor->getNumberOfParameters() === 0) {
            return new $class();
        }

        $this->resolving[$class] = true;
        try {
            $args = [];
            foreach ($ctor->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $args[] = $this->get($type->getName());
                    continue;
                }
                if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                    continue;
                }
                if ($param->allowsNull()) {
                    $args[] = null;
                    continue;
                }
                throw new RuntimeException(sprintf(
                    '%s::__construct() 的 $%s 无法自动注入，请在容器中显式注册',
                    $class,
                    $param->getName()
                ));
            }
            return $ref->newInstanceArgs($args);
        } finally {
            unset($this->resolving[$class]);
        }
    }
}
