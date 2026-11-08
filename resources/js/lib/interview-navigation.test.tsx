import { createElement } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import Created from '@/pages/interviews/created';
import Show from '@/pages/interviews/show';
import PositionShow from '@/pages/positions/show';
import { copyInterviewLink } from './copy-interview-link';

const screen = vi.hoisted(() => ({ copyState: 'idle' }));
vi.mock('react', async (original) => {
    const actual = await original<typeof import('react')>();
    return {
        ...actual,
        useState: (initial: unknown) =>
            actual.useState(initial === 'idle' ? screen.copyState : initial),
    };
});
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) =>
        createElement('a', { href }, children),
    useForm: () => ({
        data: { name: '' },
        errors: {},
        processing: false,
        post: vi.fn(),
        transform: vi.fn(),
        setData: vi.fn(),
    }),
}));
const url =
    'https://demo.example.test/candidate/interviews/fictional-private-token';
const props = {
    interviewId: 9,
    candidateUrl: url,
    position: { id: 4, title: 'Senior Laravel Developer' },
    candidateName: 'Demo Candidate',
};
const focus = vi.fn();
const select = vi.fn();
const setSelectionRange = vi.fn();
const field = {
    value: url,
    focus,
    select,
    setSelectionRange,
} as unknown as HTMLInputElement;

beforeEach(() => {
    screen.copyState = 'idle';
    vi.clearAllMocks();
});
afterEach(() => vi.unstubAllGlobals());

test('confirmation emphasizes saving the one-time link and preserves position and candidate navigation', () => {
    const html = renderToStaticMarkup(createElement(Created, props));
    expect(html).toContain('Copy Interview Link');
    expect(html).toContain('Save the private interview link before leaving');
    expect(html).toContain('expires in 24 hours');
    expect(html).toContain('original token cannot be recovered');
    expect(html).toContain('share it securely');
    expect(html).toContain('Demo Candidate');
    expect(html).toContain('Senior Laravel Developer');
    expect(html).toContain('href="/interviews/9"');
    expect(html).toContain('href="/positions/4#add-candidate"');
    expect(html).toContain('Create Another Candidate');
    expect(html).toContain('rel="noreferrer"');
    expect(html).toContain('readOnly=""');
});

test('successful copy has a visible accessible confirmation', () => {
    screen.copyState = 'copied';
    const html = renderToStaticMarkup(createElement(Created, props));
    expect(html).toContain('Interview Link Copied');
    expect(html).toContain('Interview link copied. Save it somewhere secure');
    expect(html).toContain('role="status"');
});

test('copy failure displays manual copy guidance without claiming success', () => {
    screen.copyState = 'manual';
    const html = renderToStaticMarkup(createElement(Created, props));
    expect(html).toContain(
        'Automatic copying is unavailable. The link is selected',
    );
    expect(html).toContain('Ctrl/Cmd+C');
    expect(html).not.toContain('Interview Link Copied');
});

test('clipboard API success copies only the intended link', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    expect(await copyInterviewLink(url, field)).toBe(true);
    expect(writeText).toHaveBeenCalledWith(url);
    expect(select).not.toHaveBeenCalled();
});

test('missing clipboard API falls back to field selection and legacy copy', async () => {
    const execCommand = vi.fn().mockReturnValue(true);
    vi.stubGlobal('navigator', {});
    vi.stubGlobal('document', { execCommand });
    expect(await copyInterviewLink(url, field)).toBe(true);
    expect(focus).toHaveBeenCalledOnce();
    expect(select).toHaveBeenCalledOnce();
    expect(setSelectionRange).toHaveBeenCalledWith(0, url.length);
    expect(execCommand).toHaveBeenCalledWith('copy');
});

test('denied clipboard and legacy copy return false with the link selected for manual copying', async () => {
    vi.stubGlobal('navigator', {
        clipboard: {
            writeText: vi.fn().mockRejectedValue(new Error('denied')),
        },
    });
    vi.stubGlobal('document', { execCommand: vi.fn().mockReturnValue(false) });
    expect(await copyInterviewLink(url, field)).toBe(false);
    expect(select).toHaveBeenCalledOnce();
});

test('unavailable selection and missing input do not crash copying', async () => {
    vi.stubGlobal('navigator', {});
    vi.stubGlobal('document', {});
    expect(await copyInterviewLink(url, null)).toBe(false);
    expect(
        await copyInterviewLink(url, {
            value: url,
            select,
            setSelectionRange,
            focus: () => {
                throw new Error('unavailable');
            },
        } as unknown as HTMLInputElement),
    ).toBe(false);
});

test('recruiter interview detail retains identity status full transcript evaluation and stable position navigation', () => {
    const interview: ComponentProps<typeof Show>['interview'] = {
        id: 9,
        status: 'completed',
        completed_at: '2026-11-07',
        expires_at: '2026-11-08T12:00:00Z',
        application: {
            name: 'Demo Candidate',
            job_position_id: 4,
            archived_at: null,
        },
        criteria_snapshot: {
            title: 'Senior Laravel Developer',
            version: 1,
            requirements: [
                {
                    id: 'criterion',
                    text: 'Laravel experience',
                    category: 'essential',
                    display_order: 0,
                },
            ],
        },
        messages: [
            { id: 1, role: 'assistant', content: 'Full recruiter question' },
            { id: 2, role: 'candidate', content: 'Full recruiter answer' },
        ],
        evaluation: {
            model: 'test-model',
            result: {
                criteria: [
                    {
                        criterion_id: 'criterion',
                        state: 'supported',
                        rationale: 'Evidence-based assessment',
                        evidence: [
                            { message_id: 2, quote: 'Full recruiter answer' },
                        ],
                    },
                ],
                strengths: [],
                gaps: [],
            },
        },
    };
    const html = renderToStaticMarkup(
        createElement(Show, {
            interview,
            position: { id: 4, title: 'Senior Laravel Developer' },
            linkVersion: 'demo-version',
            canRegenerateLink: false,
            linkExpired: false,
        }),
    );
    for (const text of [
        'Demo Candidate',
        'Senior Laravel Developer',
        'Completed',
        'Full recruiter question',
        'Full recruiter answer',
        'Evidence-based assessment',
    ])
        expect(html).toContain(text);
    expect(html).toContain('href="/positions/4#candidates"');
    expect(html).toContain('Back to position and candidates');
    expect(html).toContain('href="#message-2"');
    expect(html).not.toContain('fictional-private-token');
    expect(html).not.toContain('Recover interview link');
});

test('regenerated link confirmation explains revocation and keeps the existing interview and candidate context', () => {
    const html = renderToStaticMarkup(
        createElement(Created, {
            ...props,
            regenerated: true,
            expiresAt: '2026-11-08T12:00:00Z',
        }),
    );
    expect(html).toContain('New interview link generated');
    expect(html).toContain('The previous interview link is now invalid');
    expect(html).toContain(
        'candidate and interview progress have been preserved',
    );
    expect(html).toMatch(/datetime="2026-11-08T12:00:00Z"/i);
    expect(html).toContain('Copy Interview Link');
    expect(html).toContain('href="/interviews/9"');
    expect(html).toContain('Demo Candidate');
});

test.each([false, true])(
    'interview detail offers regeneration only when eligible: %s',
    (eligible) => {
        const interview: ComponentProps<typeof Show>['interview'] = {
            id: 9,
            status: eligible ? 'active' : 'completed',
            completed_at: eligible ? null : '2026-11-07',
            expires_at: '2026-11-08T12:00:00Z',
            application: {
                name: 'Demo Candidate',
                job_position_id: 4,
                archived_at: null,
            },
            criteria_snapshot: {
                title: 'Snapshot title',
                version: 1,
                requirements: [],
            },
            messages: [],
            evaluation: null,
        };
        const html = renderToStaticMarkup(
            createElement(Show, {
                interview,
                position: props.position,
                linkVersion: 'version',
                canRegenerateLink: eligible,
                linkExpired: true,
            }),
        );
        expect(html).toContain('Link expired:');
        expect(html).toContain('Senior Laravel Developer');
        if (eligible) {
            expect(html).toContain('Generate New Interview Link');
            expect(html).toContain(
                'progress and saved answers will be preserved',
            );
        } else {
            expect(html).not.toContain('Generate New Interview Link');
            expect(html).toContain(
                'New links cannot be generated for completed interviews',
            );
        }
    },
);

test('position candidate name and explicit view action navigate to the server-provided interview URL rather than the candidate ID', () => {
    const position: ComponentProps<typeof PositionShow>['position'] = {
        id: 4,
        title: 'Senior Laravel Developer',
        description: 'Demo role',
        location: 'Remote',
        employment_type: 'full_time',
        interview_language: 'tr',
        status: 'active',
        requirements: [],
        screening_topics: [],
        version: 1,
    };
    const html = renderToStaticMarkup(
        createElement(PositionShow, {
            position,
            applications: [
                {
                    id: 22,
                    name: 'Demo Candidate',
                    archived_at: null,
                    can_delete: true,
                    interview: { id: 9, status: 'active' },
                    interview_url: '/interviews/9',
                },
            ],
        }),
    );
    expect(html).toContain('Demo Candidate');
    expect(html).toContain('View Interview');
    expect(html.match(/href="\/interviews\/9"/g)).toHaveLength(2);
    expect(html).not.toContain('href="/interviews/22"');
});

test('archived candidate view includes list navigation and restore guidance without showing the add form', () => {
    const position: ComponentProps<typeof PositionShow>['position'] = {
        id: 4,
        title: 'Senior Laravel Developer',
        description: 'Demo',
        location: 'Remote',
        employment_type: 'full_time',
        interview_language: 'tr',
        status: 'active',
        requirements: [],
        screening_topics: [],
        version: 1,
    };
    const html = renderToStaticMarkup(
        createElement(PositionShow, {
            position,
            candidateFilter: 'archived',
            activeCandidateCount: 2,
            archivedCandidateCount: 1,
            applications: [
                {
                    id: 22,
                    name: 'Archived Candidate',
                    archived_at: '2026-11-07',
                    can_delete: false,
                    interview: { id: 9, status: 'completed' },
                    interview_url: '/interviews/9',
                },
            ],
        }),
    );
    expect(html).toContain('Active candidates (2)');
    expect(html).toContain('Archived candidates (1)');
    expect(html).toContain(
        'href="/positions/4?candidates=archived#candidates"',
    );
    expect(html).toContain(
        'Restore a candidate, then generate a new interview link if eligible',
    );
    expect(html).toContain('Archived Candidate');
    expect(html).not.toContain('Add candidate and create interview link');
});

test('archived interview detail keeps evidence navigation and explains that restoration precedes link regeneration', () => {
    const interview: ComponentProps<typeof Show>['interview'] = {
        id: 9,
        status: 'active',
        completed_at: null,
        expires_at: '2026-11-07T12:00:00Z',
        application: {
            name: 'Archived Candidate',
            job_position_id: 4,
            archived_at: '2026-11-07',
        },
        criteria_snapshot: { title: 'Snapshot', version: 1, requirements: [] },
        messages: [
            { id: 2, role: 'candidate', content: 'Preserved archived answer' },
        ],
        evaluation: null,
    };
    const html = renderToStaticMarkup(
        createElement(Show, {
            interview,
            position: props.position,
            linkVersion: 'version',
            canRegenerateLink: false,
            linkExpired: true,
        }),
    );
    expect(html).toContain(
        'Archived candidate. Interview history is preserved and candidate access is disabled',
    );
    expect(html).toContain(
        'href="/positions/4?candidates=archived#candidates"',
    );
    expect(html).toContain('Preserved archived answer');
    expect(html).toContain('Restore this candidate');
    expect(html).not.toContain('Generate New Interview Link');
});

test('position detail renders the stored job Markdown using the shared renderer', () => {
    const position: ComponentProps<typeof PositionShow>['position'] = {
        id: 4,
        title: 'Senior Laravel Developer',
        description:
            '**Senior Laravel Developer**\n\n## Aranan Nitelikler\n\n- Laravel\n- PostgreSQL',
        location: 'Remote',
        employment_type: 'full_time',
        interview_language: 'tr',
        status: 'active',
        requirements: [],
        screening_topics: [],
        version: 1,
    };
    const html = renderToStaticMarkup(
        createElement(PositionShow, { position }),
    );
    expect(html).toContain('<strong>Senior Laravel Developer</strong>');
    expect(html).toContain('<h2>Aranan Nitelikler</h2>');
    expect(html).toContain('<li>Laravel</li>');
    expect(position.description).toContain('**Senior Laravel Developer**');
});

test.each([
    [
        'tr',
        'Soru',
        'Yanıt',
        'Aday',
        'Görüşme durumu',
        'Tamamlandı',
        'Kriter sürümü',
    ],
    [
        'en',
        'Question',
        'Answer',
        'Candidate',
        'Interview status',
        'Completed',
        'Criteria version',
    ],
] as const)(
    'interview detail uses sequential %s labels while preserving evidence IDs',
    (language, question, answer, candidate, status, completed, criteria) => {
        const interview: ComponentProps<typeof Show>['interview'] = {
            id: 9,
            status: 'completed',
            completed_at: '2026-11-07',
            expires_at: '2026-11-08T12:00:00Z',
            application: {
                name: 'Demo',
                job_position_id: 4,
                archived_at: null,
            },
            criteria_snapshot: {
                title: props.position.title,
                version: 3,
                interview_language: language,
                requirements: [],
            },
            messages: [
                { id: 17, role: 'assistant', content: 'First question' },
                { id: 24, role: 'candidate', content: 'First answer' },
                { id: 30, role: 'assistant', content: 'Second question' },
            ],
            evaluation: {
                model: 'test-model',
                result: {
                    criteria: [
                        {
                            criterion_id: 'criterion',
                            state: 'supported',
                            rationale: 'Assessment',
                            evidence: [
                                { message_id: 24, quote: 'First answer' },
                            ],
                        },
                    ],
                    strengths: [],
                    gaps: [],
                },
            },
        };
        const render = () =>
            renderToStaticMarkup(
                createElement(Show, {
                    interview,
                    position: props.position,
                    linkVersion: 'version',
                    canRegenerateLink: false,
                    linkExpired: false,
                }),
            );
        const html = render();
        expect(html.match(/Senior Laravel Developer/g)).toHaveLength(1);
        expect(html).toContain(`${question} 1 · AI`);
        expect(html).toContain(`${question} 2 · AI`);
        expect(html).toContain(`${answer} 1 · ${candidate}`);
        expect(html).not.toContain('#17');
        expect(html).not.toContain('· assistant');
        expect(html).toContain('id="message-17"');
        expect(html).toContain('id="message-24"');
        expect(html).toContain(
            `href="#message-24">${answer} 1 · ${candidate}</a>`,
        );
        expect(html).toContain(status);
        expect(html).toContain(completed);
        expect(html).toContain(
            `${criteria}</dt><dd class="font-medium">3</dd>`,
        );
        interview.criteria_snapshot.title = 'Original position title';
        expect(render()).toContain('Original position title');
    },
);

test.each(['tr', 'en'] as const)(
    'evaluation shows explicit empty summaries in %s without inventing gaps or hiding insufficient evidence',
    (language) => {
        const interview: ComponentProps<typeof Show>['interview'] = {
            id: 3,
            status: 'completed',
            completed_at: '2026-11-08',
            expires_at: '2026-11-09T12:00:00Z',
            application: {
                name: 'Demo',
                job_position_id: 4,
                archived_at: null,
            },
            criteria_snapshot: {
                title: props.position.title,
                version: 1,
                interview_language: language,
                requirements: [],
            },
            messages: [{ id: 18, role: 'candidate', content: 'Saved answer' }],
            evaluation: {
                model: 'gpt-4.1-mini',
                result: {
                    criteria: [
                        {
                            criterion_id: 'criterion',
                            state: 'insufficient_evidence',
                            rationale:
                                'Not enough evidence for this criterion.',
                            evidence: [
                                { message_id: 18, quote: 'Saved answer' },
                            ],
                        },
                    ],
                    strengths: [],
                    gaps: [],
                },
            },
        };
        const render = () =>
            renderToStaticMarkup(
                createElement(Show, {
                    interview,
                    position: props.position,
                    linkVersion: 'version',
                    canRegenerateLink: false,
                    linkExpired: false,
                }),
            );
        const emptyGaps =
            language === 'tr'
                ? 'Hiçbir kriter eksiklik olarak değerlendirilmedi.'
                : 'No criteria were assessed as a gap.';
        const emptyStrengths =
            language === 'tr'
                ? 'Hiçbir kriter desteklenmiş olarak değerlendirilmedi.'
                : 'No criteria were assessed as supported.';
        const html = render();
        expect(html).toContain(emptyGaps);
        expect(html).toContain(emptyStrengths);
        expect(html).toContain('Not enough evidence for this criterion.');
        expect(html).toContain('href="#message-18"');
        expect(html).toContain('gpt-4.1-mini');
        interview.evaluation!.result.gaps = ['Existing saved gap'];
        interview.evaluation!.result.strengths = ['Existing saved strength'];
        const populated = render();
        expect(populated).toContain('<li>Existing saved gap</li>');
        expect(populated).toContain('<li>Existing saved strength</li>');
        expect(populated).not.toContain(emptyGaps);
        expect(populated).not.toContain(emptyStrengths);
    },
);

test.each(['evaluated', 'completed', 'active'] as const)(
    'recruiter detail places evaluation before the full transcript and secondary actions: %s',
    (state) => {
        const interview: ComponentProps<typeof Show>['interview'] = {
            id: 3,
            status: state === 'active' ? 'active' : 'completed',
            completed_at: state === 'active' ? null : '2026-11-08',
            expires_at: '2026-11-09T12:00:00Z',
            application: {
                name: 'Demo candidate',
                job_position_id: 4,
                archived_at: null,
            },
            criteria_snapshot: {
                title: props.position.title,
                version: 1,
                requirements: [
                    {
                        id: 'criterion',
                        text: 'Saved criterion',
                        category: 'essential',
                        display_order: 0,
                    },
                ],
            },
            messages: [
                { id: 17, role: 'assistant', content: 'Entire saved question' },
                { id: 18, role: 'candidate', content: 'Entire saved answer' },
            ],
            evaluation:
                state === 'evaluated'
                    ? {
                          model: 'test-model',
                          result: {
                              criteria: [
                                  {
                                      criterion_id: 'criterion',
                                      state: 'supported',
                                      rationale: 'Saved rationale',
                                      evidence: [
                                          {
                                              message_id: 18,
                                              quote: 'Entire saved answer',
                                          },
                                      ],
                                  },
                              ],
                              strengths: ['Saved strength'],
                              gaps: ['Saved gap'],
                          },
                      }
                    : null,
        };
        const html = renderToStaticMarkup(
            createElement(Show, {
                interview,
                position: props.position,
                linkVersion: 'version',
                canRegenerateLink: state === 'active',
                linkExpired: false,
            }),
        );
        const evaluation = html.indexOf('Evidence-based AI evaluation');
        const transcript = html.indexOf('>Transcript</h2>');
        expect(evaluation).toBeGreaterThan(
            html.indexOf('Demo candidate — fictional candidate'),
        );
        expect(evaluation).toBeGreaterThan(html.indexOf(props.position.title));
        expect(evaluation).toBeGreaterThan(
            html.indexOf(state === 'active' ? 'In progress' : 'Completed'),
        );
        expect(transcript).toBeGreaterThan(evaluation);
        expect(html.indexOf('id="message-17"')).toBeGreaterThan(transcript);
        expect(html.indexOf('id="message-18"')).toBeGreaterThan(transcript);
        expect(html.indexOf('Back to position and candidates')).toBeGreaterThan(
            html.indexOf('id="message-18"'),
        );
        expect(html.indexOf('Refresh transcript')).toBeGreaterThan(transcript);
        expect(html.indexOf('Candidate interview access')).toBeGreaterThan(
            transcript,
        );
        expect(html).toContain('Entire saved question');
        expect(html).toContain('Entire saved answer');
        if (state === 'evaluated') {
            for (const content of [
                'Saved criterion',
                'Saved rationale',
                'Saved strength',
                'Saved gap',
                'href="#message-18"',
            ]) {
                expect(html.indexOf(content)).toBeGreaterThan(evaluation);
                expect(html.indexOf(content)).toBeLessThan(transcript);
            }
            expect(html).not.toContain('Generate evaluation / retry');
        } else {
            expect(html.indexOf('Generate evaluation / retry')).toBeGreaterThan(
                evaluation,
            );
            expect(html.indexOf('Generate evaluation / retry')).toBeLessThan(
                transcript,
            );
            if (state === 'active') {
                expect(
                    html.indexOf(
                        'Complete the candidate interview before generating',
                    ),
                ).toBeLessThan(transcript);
                expect(
                    html.indexOf('Generate New Interview Link'),
                ).toBeGreaterThan(transcript);
            }
        }
    },
);
