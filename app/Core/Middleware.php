<?php
namespace App\Core;

abstract class Middleware {
    abstract public function handle(): void;

    public static function run(array $middlewares): void {
        foreach ($middlewares as $mw) {
            if (is_string($mw)) {
                (new $mw())->handle();
            } elseif (is_array($mw)) {
                [$class, $args] = $mw;
                (new $class(...(array) $args))->handle();
            }
        }
    }
}
