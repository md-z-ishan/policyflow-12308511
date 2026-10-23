<?php

namespace App\Http\Requests;

use App\Enums\Destination;
use App\Enums\MockScenario;
use App\Enums\PlanCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StorePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'client_request_id' => ['required', 'string', 'max:100'],
            'agent_id' => ['required', 'string', 'max:100'],
            'customer_name' => ['required', 'string', 'max:150'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'destination' => ['required', 'string', Rule::enum(Destination::class)],
            'trip_start' => ['required', 'date'],
            'trip_end' => ['required', 'date', 'after_or_equal:trip_start'],
            'plan_code' => ['required', 'string', Rule::enum(PlanCode::class)],
            'sum_insured' => ['required', 'integer', Rule::in([100000, 300000, 500000])],
        ];

        if (app()->environment('local', 'testing')) {
            $rules['mock_scenario'] = ['nullable', 'string', Rule::enum(MockScenario::class)];
        } else {
            $rules['mock_scenario'] = ['prohibited'];
        }

        return $rules;
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
