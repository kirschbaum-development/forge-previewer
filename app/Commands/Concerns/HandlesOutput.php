<?php

namespace App\Commands\Concerns;

use function Termwind\render;

trait HandlesOutput
{
    /**
     * Render the field-level errors from a Forge 422 response and fail.
     *
     * @param  array<string, array<int, string>|string>  $errors
     */
    protected function bailValidation(array $errors): int
    {
        $lines = [];

        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $lines[] = is_string($field) && ! is_numeric($field)
                    ? "{$field}: {$message}"
                    : $message;
            }
        }

        $detail = $lines === [] ? '' : ' ' . implode(' | ', $lines);

        return $this->bail('Forge rejected the request (422 validation error).' . $detail);
    }

    protected function bail(string $message): int
    {
        render(sprintf(<<<'html'
            <div class="font-bold">
                <span class="bg-red px-2 text-white mr-1">
                    ERROR
                </span>
                %s
            </div>
        html, trim($message)));

        return 1;
    }

    protected function information(string $message)
    {
        render(sprintf(<<<'html'
            <div class="font-bold">
                <span class="bg-blue px-2 text-white mr-1">
                    INFO
                </span>
                %s
            </div>
        html, trim($message)));
    }

    protected function success(string $message)
    {
        render(sprintf(<<<'html'
            <div class="font-bold">
                <span class="bg-green px-2 text-white mr-1">
                    SUCCESS
                </span>
                %s
            </div>
        html, trim($message)));
    }
}
