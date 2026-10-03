<?php

/**
 * 前端控制器（唯一入口）
 *
 * 所有请求都从这里进入，由路由器分发。旧版是「每个 URL 一个 .php 文件」，
 * 于是公共逻辑（bootstrap、权限判断、flash 渲染）在每个文件里各写一遍，
 * 页面之间还会互相 require。<main> 标签甚至被两个页面重复关闭过一次
 * （页脚本来就会关闭它），因为没人能一眼看出谁负责哪一段。
 *
 * 这里只有一个出口：公共逻辑在中间件里，缓存策略在 Application::run() 里
 * （它需要与请求收尾钩子共用同一个「是否匿名」的判断，放在这里会各判一次）。
 */
declare(strict_types=1);

use App\Core\App;
use App\Core\Request;

$appRoot = dirname(__DIR__);

/** @var App\Core\Application $app */
$app = require $appRoot . '/bootstrap.php';

$request = Request::fromGlobals();
$response = $app->run($request);
$response = $app->securityHeaders($response);
$response->send();
