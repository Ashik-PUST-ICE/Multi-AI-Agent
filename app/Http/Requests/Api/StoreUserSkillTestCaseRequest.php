<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreUserSkillTestCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'prompt' => ['required', 'string', 'max:5000'],
            'reference_answer' => ['nullable', 'string', 'max:5000'],
            'criteria' => ['nullable', 'array', 'max:10'],
            'criteria.*' => ['required', 'string', 'max:250'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $referenceAnswer = $this->input('reference_answer');
                $criteria = $this->input('criteria');
                $hasReference = is_string($referenceAnswer) && trim($referenceAnswer) !== '';
                $hasCriteria = is_array($criteria) && $criteria !== [];

                if (! $hasReference && ! $hasCriteria) {
                    $validator->errors()->add(
                        'criteria',
                        'Provide a reference answer or at least one evaluation criterion.',
                    );
                }
            },
        ];
    }
}
