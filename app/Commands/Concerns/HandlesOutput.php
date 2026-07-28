<?php

namespace App\Commands\Concerns;

use function Termwind\render;

trait HandlesOutput
{
    /**
     * Render the field-level errors from a Forge 422 response and fail.
     *
     * The SDK hands back the decoded response body, which is typically shaped
     * as ['message' => ..., 'errors' => ['field' => ['message', ...]]]. We pull
     * the nested "errors" bag when present and flatten every message.
     *
     * @param  array<mixed>  $errors
     */
    protected function bailValidation(array $errors): int
    {
        $lines = $this->flattenValidationMessages($errors);

        $detail = $lines === [] ? '' : ' ' . implode(' | ', $lines);

        return $this->bail('Forge rejected the request (422 validation error).' . $detail);
    }

    /**
     * Flatten a Forge 422 body (['message' => ..., 'errors' => ['field' => ['msg']]])
     * into a de-duplicated list of message strings.
     *
     * @param  array<mixed>  $errors
     * @return array<int, string>
     */
    protected function flattenValidationMessages(array $errors): array
    {
        $bag = isset($errors['errors']) && is_array($errors['errors'])
            ? $errors['errors']
            : $errors;

        $lines = [];

        array_walk_recursive($bag, function ($message) use (&$lines) {
            if (is_scalar($message)) {
                $lines[] = (string) $message;
            }
        });

        return array_values(array_unique($lines));
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
