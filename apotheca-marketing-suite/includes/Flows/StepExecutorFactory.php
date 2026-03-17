<?php

namespace Apotheca\Marketing\Flows;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StepExecutorFactory {

    private static array $map = [
        'send_email'   => Steps\SendEmail::class,
        'send_sms'     => Steps\SendSMS::class,
        'add_tag'      => Steps\AddTag::class,
        'remove_tag'   => Steps\RemoveTag::class,
        'update_field' => Steps\UpdateField::class,
        'condition'    => Steps\Condition::class,
        'wait'         => Steps\Wait::class,
        'exit'         => Steps\ExitStep::class,
    ];

    public static function create( string $step_type ): ?StepExecutorInterface {
        $class = self::$map[ $step_type ] ?? null;
        if ( $class && class_exists( $class ) ) {
            return new $class();
        }
        return null;
    }

    public static function valid_types(): array {
        return array_keys( self::$map );
    }
}
