<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base for admin-panel AJAX endpoints. Failures answer with the panel's usual
 * ResponseService envelope ({error, message, data, code}, HTTP 200) instead of
 * Laravel's 422 / 403, so the existing ajaxRequest() / $.get handlers can read them.
 *
 * Subclasses list the permissions that may call them in PERMISSIONS (any one is enough).
 */
abstract class AdminJsonRequest extends FormRequest
{
    /** @var string[] */
    protected const PERMISSIONS = [];

    public function authorize(): bool
    {
        return static::PERMISSIONS === [] || (bool) $this->user()?->canany(static::PERMISSIONS);
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException($this->envelope(__("You Don't have enough permissions"), config('constants.RESPONSE_CODE.EXCEPTION_ERROR')));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException($this->envelope($validator->errors()->first(), config('constants.RESPONSE_CODE.VALIDATION_ERROR')));
    }

    private function envelope(string $message, int|string|null $code)
    {
        return response()->json(['error' => true, 'message' => $message, 'data' => null, 'code' => $code]);
    }
}
