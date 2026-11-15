<?php

declare(strict_types=1);

namespace Sahil\Validation;

use Sahil\Http\Request;

/**
 * Pull the validator INPUT from a Sahil HTTP request.
 *
 * Resolution order (explicitly documented, deterministic):
 *   1. query parameters,
 *   2. body fields (win over query values on key collisions):
 *      - `application/json`  → the parsed JSON tree (bad JSON throws a
 *        `BadRequestHttpException` from the request object itself),
 *      - `application/x-www-form-urlencoded` / multipart → string form map,
 *      - other content types → no body fields.
 *
 * The returned array is RAW input. It is the validator's job to separate
 * validation from persistence: use {@see ValidationResult::data()} — never
 * this payload — for anything you act on.
 *
 * @return array<string, mixed>
 */
final class FormInput
{
    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request): array
    {
        $payload = $request->query();

        if ($request->hasJsonBody()) {
            $fields = $request->json();
        } elseif ($request->hasFormBody()) {
            $fields = $request->form();
        } else {
            $fields = [];
        }

        foreach ($fields as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }
}
