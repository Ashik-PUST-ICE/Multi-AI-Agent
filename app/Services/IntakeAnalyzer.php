<?php

namespace App\Services;

use App\Agents\SchemaValidator;

class IntakeAnalyzer
{
    public function __construct(private readonly SchemaValidator $schemaValidator) {}

    /**
     * Analyze the original request using bounded local rules only.
     *
     * @return array<string, mixed>
     */
    public function analyze(string $prompt): array
    {
        $normalized = mb_strtolower($prompt);
        $language = $this->language($prompt);
        $intent = $this->intent($normalized);
        $risk = $this->risk($normalized);
        $complexityScore = min(100, (int) (mb_strlen($prompt) / 80)
            + (preg_match_all('/\b(and|then|compare|analyze|multiple|এবং|তারপর|তুলনা|বিশ্লেষণ)\b/u', $normalized) * 15));
        $complexity = $complexityScore >= 60 ? 'complex' : ($complexityScore >= 25 ? 'moderate' : 'simple');
        $questions = $this->clarificationQuestions($normalized, $intent);
        $criteria = $this->suggestedCriteria($intent, $language, $risk['level']);

        $brief = [
            'schema_version' => 1,
            'language' => $language,
            'intent' => $intent,
            'entities' => $this->entities($prompt),
            'ambiguity' => [
                'level' => $questions === [] ? 'low' : 'medium',
                'questions' => array_slice($questions, 0, 2),
            ],
            'risk' => $risk,
            'complexity' => [
                'level' => $complexity,
                'score' => $complexityScore,
            ],
            'success_criteria_suggestions' => $criteria,
            'resources' => [
                'laptop_required' => false,
                'tools' => $intent === 'research' ? ['web_search (if permitted by the active agent spec)'] : [],
                'cost_estimate' => 'See the separate model and token estimate.',
                'duration_estimate' => 'Not available; no task history estimate is configured.',
            ],
            'analysis_method' => 'local_rules',
            'original_prompt_preserved' => true,
        ];

        $this->schemaValidator->validate($brief, config('task_brief.schema'));

        return $brief;
    }

    private function language(string $prompt): string
    {
        $hasBengali = preg_match('/[\x{0980}-\x{09FF}]/u', $prompt) === 1;
        $romanBengali = preg_match('/\b(ami|tumi|koro|korbo|hobe|ache|eta|ki|keno|bhalo|chai|dao|suru|next|phase)\b/i', $prompt) === 1;
        $hasLatin = preg_match('/[A-Za-z]/', $prompt) === 1;

        if ($hasBengali && $hasLatin) {
            return 'mixed';
        }
        if ($hasBengali) {
            return 'bengali';
        }
        if ($romanBengali) {
            return 'banglish';
        }

        return $hasLatin ? 'english' : 'unknown';
    }

    private function intent(string $prompt): string
    {
        return match (true) {
            preg_match('/\b(code|bug|program|software|test|refactor)\b|কোড|বাগ|প্রোগ্রাম|টেস্ট/u', $prompt) === 1 => 'code',
            preg_match('/\b(dataset|data|calculate|statistics|spreadsheet)\b|ডেটা|হিসাব|পরিসংখ্যান/u', $prompt) === 1 => 'data',
            preg_match('/\b(review|critique|proofread|verify|check)\b|পর্যালোচনা|যাচাই|প্রুফরিড/u', $prompt) === 1 => 'review',
            preg_match('/\b(send|delete|pay|purchase|call|book|submit|post)\b|পাঠাও|মুছ|কেনা|পেমেন্ট|কল কর|বুক কর/u', $prompt) === 1 => 'action',
            preg_match('/\b(research|investigate|sources|find facts)\b|গবেষণা|তথ্য খুঁজ|উৎসসহ/u', $prompt) === 1 => 'research',
            preg_match('/\b(write|draft|email|summari[sz]e|translate)\b|লিখ|ইমেইল|সারাংশ|অনুবাদ/u', $prompt) === 1 => 'writing',
            preg_match('/\b(plan|organize|manage|goal|mission)\b|পরিকল্পনা|ব্যবস্থা কর|লক্ষ্য/u', $prompt) === 1 => 'goal',
            preg_match('/\b(hi|hello|thanks|thank you)\b|হ্যালো|ধন্যবাদ/u', $prompt) === 1 => 'conversation',
            default => 'question',
        };
    }

    /**
     * @return array{level: string, reasons: list<string>, never_auto_indicators: list<string>, required_trust_level: int}
     */
    private function risk(string $prompt): array
    {
        $reasons = [];
        $neverAuto = [];
        if (preg_match('/\b(send|email|message|post|publish|call|book|submit)\b|পাঠাও|ইমেইল কর|প্রকাশ কর/u', $prompt) === 1) {
            $reasons[] = 'external_side_effect_possible';
        }
        if (preg_match('/\b(pay|payment|purchase|transfer|bank|invest)\b|পেমেন্ট|টাকা পাঠা|কেনাকাটা|বিনিয়োগ/u', $prompt) === 1) {
            $reasons[] = 'financial_action_possible';
            $neverAuto[] = 'financial_action';
        }
        if (preg_match('/\b(password|secret|credential|api key|medical|diagnosis|legal advice)\b|পাসওয়ার্ড|গোপন তথ্য|চিকিৎসা/u', $prompt) === 1) {
            $reasons[] = 'sensitive_information_or_advice';
        }
        if (preg_match('/\b(lock|stove|gas|door lock)\b|তালা|চুলা|গ্যাস/u', $prompt) === 1) {
            $reasons[] = 'physical_device_action_possible';
            $neverAuto[] = 'physical_safety_device';
        }

        $level = $neverAuto !== [] ? 'high' : ($reasons !== [] ? 'medium' : 'low');
        $trust = $level === 'high'
            ? AGENT_TRUST_LEVEL_SENSITIVE
            : ($level === 'medium' ? AGENT_TRUST_LEVEL_OUTBOUND : AGENT_TRUST_LEVEL_NONE);

        return [
            'level' => $level,
            'reasons' => $reasons,
            'never_auto_indicators' => $neverAuto,
            'required_trust_level' => $trust,
            'advisory_only' => true,
        ];
    }

    /**
     * @return list<array{type: string, value: string}>
     */
    private function entities(string $prompt): array
    {
        $entities = [];
        if (preg_match_all('/\b(?:20\d{2}[-\/]\d{1,2}[-\/]\d{1,2}|\d{1,2}\s+(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+20\d{2})\b/i', $prompt, $matches)) {
            foreach (array_unique($matches[0]) as $value) {
                $entities[] = ['type' => 'date', 'value' => $value];
            }
        }
        if (preg_match_all('/(?:^|\s)([A-Za-z0-9_.-]+\.(?:pdf|csv|xlsx?|docx?|txt|php|js|ts|py))\b/i', $prompt, $matches)) {
            foreach (array_unique($matches[1]) as $value) {
                $entities[] = ['type' => 'file', 'value' => $value];
            }
        }

        return array_slice($entities, 0, 10);
    }

    /**
     * @return list<string>
     */
    private function clarificationQuestions(string $prompt, string $intent): array
    {
        $questions = [];
        if (preg_match('/\b(it|this|that|them|ওটা|এটা|সেটা)\b/u', $prompt) === 1
            && mb_strlen($prompt) < 120) {
            $questions[] = 'What does the referenced item mean in this request?';
        }
        if ($intent === 'writing'
            && preg_match('/\b(for|audience|tone|format|কার জন্য|ধরন)\b/u', $prompt) !== 1
            && mb_strlen($prompt) < 100) {
            $questions[] = 'Who is the intended audience or what format should the writing use?';
        }

        return array_slice($questions, 0, 2);
    }

    /**
     * @return list<string>
     */
    private function suggestedCriteria(string $intent, string $language, string $risk): array
    {
        $criteria = match ($intent) {
            'research' => ['Separate supported facts from uncertainty.', 'Include sources when available.'],
            'code' => ['Keep the change within the requested scope.', 'Run focused tests and report their results.'],
            'review' => ['Report concrete findings with evidence and severity.'],
            'data' => ['State the method, assumptions, and data limitations.'],
            'action' => ['Do not perform an external action without the required explicit approval.'],
            default => ['Address the original request and disclose material uncertainty.'],
        };
        if ($language === 'bengali' || $language === 'banglish' || $language === 'mixed') {
            $criteria[] = 'Preserve the user’s original language preference where practical.';
        }
        if ($risk !== 'low') {
            $criteria[] = 'Respect the existing trust, approval, and access policies.';
        }

        return array_values(array_unique($criteria));
    }
}
