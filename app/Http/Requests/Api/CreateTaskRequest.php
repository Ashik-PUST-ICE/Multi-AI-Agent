<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class CreateTaskRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'prompt' => ['required', 'string', 'max:20000'],
            'routing_strategy' => ['sometimes', 'string', 'in:single_agent,multi_agent'],
            'success_criteria' => ['sometimes', 'array', 'max:10'],
            'success_criteria.*' => ['required', 'string', 'max:500', 'distinct'],
            'plan_review' => ['sometimes', 'array'],
            'plan_review.required' => ['sometimes', 'boolean'],
            'plan_review.status' => ['sometimes', 'string', 'in:pending,approved,rejected'],
            'plan_review.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'max_cost' => ['sometimes', 'nullable', 'numeric', 'min:0.000001', 'max:1000000'],
            'agent_pack' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'knowledge_document_ids' => ['sometimes', 'array', 'max:100'],
            'knowledge_document_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'knowledge_context_token' => ['sometimes', 'nullable', 'string', 'size:64', 'alpha_num'],
            'memory_ids' => ['sometimes', 'array', 'max:10'],
            'memory_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'memory_context_hash' => ['sometimes', 'nullable', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'task_brief_review_token' => ['sometimes', 'string', 'max:4096'],
            'requires_device' => ['sometimes', 'boolean'],
            'device_token_id' => ['required_if:requires_device,true', 'integer', 'min:1'],
            'queue_priority' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'queue_expires_at' => ['sometimes', 'date', 'after:now'],
            'vision_image' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'vision_confirmed' => ['sometimes', 'accepted'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $this->hasFile('vision_image')
                    && ! in_array($this->input('vision_confirmed'), ['yes', 'on', 1, '1', true, 'true'], true)
                    && ! $validator->errors()->has('vision_confirmed')
                ) {
                    $validator->errors()->add('vision_confirmed', 'Explicit confirmation is required before uploading an image.');
                }
                $requiresDevice = in_array($this->input('requires_device'), [true, 1, '1', 'true'], true);
                if (! $requiresDevice && ($this->exists('device_token_id') || $this->exists('queue_expires_at') || $this->exists('queue_priority'))) {
                    $validator->errors()->add('requires_device', 'Device queue options require requires_device=true.');
                }
                if ($requiresDevice && $this->filled('queue_expires_at')) {
                    try {
                        $expiry = Carbon::parse($this->input('queue_expires_at'));
                        if ($expiry->greaterThan(now()->addDays(30))) {
                            $validator->errors()->add('queue_expires_at', 'Device queue entries may expire no more than 30 days from now.');
                        }
                    } catch (\Throwable) {
                        // The date rule reports invalid date input.
                    }
                }
            },
        ];
    }
}
