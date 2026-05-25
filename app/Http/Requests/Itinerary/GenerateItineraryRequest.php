<?php

namespace App\Http\Requests\Itinerary;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class GenerateItineraryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'origin' => ['required', 'string', 'min:2', 'max:100'],
            'destination_label' => ['required', 'string', 'min:2', 'max:100'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:30'],
            'num_people' => ['required', 'integer', 'min:1', 'max:20'],
            'budget' => ['required', 'numeric', 'min:0'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('Validation failed.', $validator->errors(), 422)
        );
    }
}
