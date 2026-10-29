<?php

return [
    'packs' => [
        'research' => [
            'name' => 'Research Assistant',
            'description' => 'Evidence-first research that separates verified information from uncertainty.',
            'agents' => ['Orchestrator', 'Research', 'Writer', 'Reviewer'],
            'guidance' => [
                'Research' => 'Prefer verifiable evidence, identify source limitations, and clearly separate facts from uncertainty.',
                'Writer' => 'Present a structured research summary and distinguish evidence from interpretation.',
            ],
            'examples' => ['Compare two approaches using cited evidence.', 'Summarize a topic and list unresolved questions.'],
        ],
        'writing-translation' => [
            'name' => 'Writing & Translation',
            'description' => 'Drafting, editing, and translation while preserving the requested meaning and tone.',
            'agents' => ['Orchestrator', 'Research', 'Writer', 'Reviewer'],
            'guidance' => [
                'Writer' => 'Preserve the requested meaning, audience, tone, and language. Do not invent source facts.',
                'Reviewer' => 'Check clarity, tone, terminology consistency, and whether the requested meaning was preserved.',
            ],
            'examples' => ['Translate this paragraph into Bengali while keeping a formal tone.', 'Edit this announcement for clarity.'],
        ],
        'data-analysis' => [
            'name' => 'Data Analysis',
            'description' => 'Careful analytical summaries with explicit assumptions and limitations.',
            'agents' => ['Orchestrator', 'Research', 'Writer', 'Reviewer'],
            'guidance' => [
                'Research' => 'Identify the available evidence, assumptions, and data limitations before drawing conclusions.',
                'Writer' => 'Explain the analysis step by step and do not claim computations that were not performed.',
                'Reviewer' => 'Check arithmetic claims, assumptions, and whether conclusions follow from the evidence.',
            ],
            'examples' => ['Explain what conclusions can and cannot be drawn from these figures.', 'Compare the stated trends and limitations.'],
        ],
        'coding' => [
            'name' => 'Coding Assistant',
            'description' => 'Software engineering assistance focused on correctness, tests, and minimal changes.',
            'agents' => ['Orchestrator', 'Research', 'Writer', 'Reviewer'],
            'guidance' => [
                'Orchestrator' => 'Break the software task into small, verifiable steps and identify relevant tests.',
                'Writer' => 'Prefer minimal, maintainable changes and explain validation needed.',
                'Reviewer' => 'Look for regressions, missing tests, and violations of the stated requirements.',
            ],
            'examples' => ['Review this function for correctness and suggest focused tests.', 'Plan a small API endpoint implementation.'],
        ],
        'business-assistant' => [
            'name' => 'Business Assistant',
            'description' => 'Practical business writing and analysis with clear assumptions and decision points.',
            'agents' => ['Orchestrator', 'Research', 'Writer', 'Reviewer'],
            'guidance' => [
                'Research' => 'Separate supplied business facts from assumptions and avoid unsupported market claims.',
                'Writer' => 'Provide a concise, actionable business response and make assumptions explicit.',
                'Reviewer' => 'Check that recommendations follow from supplied evidence and call out missing information.',
            ],
            'examples' => ['Draft a concise project update for stakeholders.', 'Compare these proposals using the supplied criteria.'],
        ],
    ],
];
