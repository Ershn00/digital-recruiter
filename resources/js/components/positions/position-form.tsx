import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    Position,
    PositionOptions,
    Requirement,
    ScreeningTopic,
} from '@/types/positions';
import { label } from '@/types/positions';

const fieldClass =
    'w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus-visible:outline-2 focus-visible:outline-ring';
function move<T>(items: T[], index: number, delta: number): T[] {
    const next = [...items];
    const target = index + delta;
    if (target < 0 || target >= items.length) return items;
    [next[index], next[target]] = [next[target], next[index]];
    return next;
}
export default function PositionForm({
    position,
    employmentTypes,
    languages,
}: PositionOptions & { position?: Position }) {
    const form = useForm({
        title: position?.title ?? '',
        description: position?.description ?? '',
        location: position?.location ?? '',
        employment_type: position?.employment_type ?? 'full_time',
        interview_language: position?.interview_language ?? 'en',
        status: position?.status === 'active' ? 'active' : 'draft',
        requirements: position?.requirements ?? ([] as Requirement[]),
        screening_topics:
            position?.screening_topics ?? ([] as ScreeningTopic[]),
        ...(position ? { version: position.version } : {}),
    });
    const errors = form.errors as Record<string, string>;
    const error = (key: string) => <InputError message={errors[key]} />;
    const requirements = (items: Requirement[]) =>
        form.setData(
            'requirements',
            items.map((item, display_order) => ({ ...item, display_order })),
        );
    const topics = (items: ScreeningTopic[]) =>
        form.setData(
            'screening_topics',
            items.map((item, display_order) => ({ ...item, display_order })),
        );
    function submit(event: FormEvent) {
        event.preventDefault();
        if (position) form.patch(`/positions/${position.id}`);
        else form.post('/positions');
    }
    return (
        <form onSubmit={submit} className="max-w-4xl space-y-8">
            {Object.keys(errors).length > 0 && (
                <div
                    role="alert"
                    className="rounded-lg border border-destructive p-4 text-sm"
                >
                    <p>Please correct the highlighted fields.</p>
                    {error('version')}
                    {error('status')}
                    {error('requirements')}
                    {error('screening_topics')}
                </div>
            )}
            <fieldset
                disabled={form.processing}
                className="space-y-6 disabled:opacity-60"
            >
                <div className="grid gap-2">
                    <Label htmlFor="title">Position title</Label>
                    <Input
                        id="title"
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        maxLength={200}
                        required
                    />
                    {error('title')}
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="description">Job description</Label>
                    <textarea
                        id="description"
                        className={fieldClass}
                        rows={7}
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                        maxLength={20000}
                        required
                    />
                    {error('description')}
                </div>
                <div className="grid gap-6 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="location">Location</Label>
                        <Input
                            id="location"
                            placeholder="Istanbul, remote, or hybrid"
                            value={form.data.location}
                            onChange={(e) =>
                                form.setData('location', e.target.value)
                            }
                            maxLength={200}
                            required
                        />
                        {error('location')}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="employment_type">Employment type</Label>
                        <select
                            id="employment_type"
                            className={fieldClass}
                            value={form.data.employment_type}
                            onChange={(e) =>
                                form.setData('employment_type', e.target.value)
                            }
                        >
                            {employmentTypes.map((value) => (
                                <option key={value} value={value}>
                                    {label(value)}
                                </option>
                            ))}
                        </select>
                        {error('employment_type')}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="interview_language">
                            Interview language
                        </Label>
                        <select
                            id="interview_language"
                            className={fieldClass}
                            value={form.data.interview_language}
                            onChange={(e) =>
                                form.setData(
                                    'interview_language',
                                    e.target.value,
                                )
                            }
                        >
                            {languages.map((value) => (
                                <option key={value} value={value}>
                                    {label(value)}
                                </option>
                            ))}
                        </select>
                        {error('interview_language')}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="status">Status</Label>
                        <select
                            id="status"
                            className={fieldClass}
                            value={form.data.status}
                            onChange={(e) =>
                                form.setData('status', e.target.value)
                            }
                        >
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                        </select>
                    </div>
                </div>
                <section
                    className="space-y-4"
                    aria-labelledby="requirements-heading"
                >
                    <h2
                        id="requirements-heading"
                        className="text-lg font-semibold"
                    >
                        Job requirements
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Add essential or desirable requirements. Use the arrows
                        to set their display order.
                    </p>
                    {form.data.requirements.length === 0 && (
                        <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                            No requirements yet. Add one to describe the role.
                        </p>
                    )}
                    {form.data.requirements.map((item, index) => (
                        <div
                            key={item.id}
                            className="space-y-3 rounded-lg border p-4"
                        >
                            <Label htmlFor={`requirement-${item.id}`}>
                                Requirement {index + 1}
                            </Label>
                            <textarea
                                id={`requirement-${item.id}`}
                                rows={2}
                                className={fieldClass}
                                value={item.text}
                                required
                                maxLength={2000}
                                onChange={(e) =>
                                    requirements(
                                        form.data.requirements.map((row) =>
                                            row.id === item.id
                                                ? {
                                                      ...row,
                                                      text: e.target.value,
                                                  }
                                                : row,
                                        ),
                                    )
                                }
                            />
                            {error(`requirements.${index}.text`)}
                            {error(`requirements.${index}.id`)}
                            {error(`requirements.${index}.display_order`)}
                            <div className="flex flex-wrap gap-2">
                                <select
                                    aria-label={`Requirement ${index + 1} category`}
                                    className={fieldClass + ' sm:w-auto'}
                                    value={item.category}
                                    onChange={(e) =>
                                        requirements(
                                            form.data.requirements.map((row) =>
                                                row.id === item.id
                                                    ? {
                                                          ...row,
                                                          category: e.target
                                                              .value as Requirement['category'],
                                                      }
                                                    : row,
                                            ),
                                        )
                                    }
                                >
                                    <option value="essential">Essential</option>
                                    <option value="desirable">Desirable</option>
                                </select>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={index === 0}
                                    aria-label={`Move requirement ${index + 1} up`}
                                    onClick={() =>
                                        requirements(
                                            move(
                                                form.data.requirements,
                                                index,
                                                -1,
                                            ),
                                        )
                                    }
                                >
                                    ↑
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={
                                        index ===
                                        form.data.requirements.length - 1
                                    }
                                    aria-label={`Move requirement ${index + 1} down`}
                                    onClick={() =>
                                        requirements(
                                            move(
                                                form.data.requirements,
                                                index,
                                                1,
                                            ),
                                        )
                                    }
                                >
                                    ↓
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        requirements(
                                            form.data.requirements.filter(
                                                (row) => row.id !== item.id,
                                            ),
                                        );
                                        topics(
                                            form.data.screening_topics.map(
                                                (topic) =>
                                                    topic.requirement_id ===
                                                    item.id
                                                        ? {
                                                              ...topic,
                                                              requirement_id:
                                                                  null,
                                                          }
                                                        : topic,
                                            ),
                                        );
                                    }}
                                >
                                    Remove
                                </Button>
                            </div>
                            {error(`requirements.${index}.category`)}
                        </div>
                    ))}
                    <Button
                        type="button"
                        variant="outline"
                        disabled={form.data.requirements.length >= 50}
                        onClick={() =>
                            requirements([
                                ...form.data.requirements,
                                {
                                    id: crypto.randomUUID(),
                                    text: '',
                                    category: 'essential',
                                    display_order:
                                        form.data.requirements.length,
                                },
                            ])
                        }
                    >
                        Add requirement
                    </Button>
                </section>
                <section className="space-y-4" aria-labelledby="topics-heading">
                    <h2 id="topics-heading" className="text-lg font-semibold">
                        Screening topics and questions
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Optionally link each topic to a requirement. Removing a
                        requirement clears its topic links.
                    </p>
                    {form.data.screening_topics.length === 0 && (
                        <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                            No screening topics yet. Add a topic or question to
                            gather job-related information.
                        </p>
                    )}
                    {form.data.screening_topics.map((item, index) => (
                        <div
                            key={item.id}
                            className="space-y-3 rounded-lg border p-4"
                        >
                            <Label htmlFor={`topic-${item.id}`}>
                                Topic or question {index + 1}
                            </Label>
                            <textarea
                                id={`topic-${item.id}`}
                                rows={2}
                                className={fieldClass}
                                value={item.text}
                                required
                                maxLength={2000}
                                onChange={(e) =>
                                    topics(
                                        form.data.screening_topics.map((row) =>
                                            row.id === item.id
                                                ? {
                                                      ...row,
                                                      text: e.target.value,
                                                  }
                                                : row,
                                        ),
                                    )
                                }
                            />
                            {error(`screening_topics.${index}.text`)}
                            {error(`screening_topics.${index}.id`)}
                            {error(`screening_topics.${index}.display_order`)}
                            <Label htmlFor={`reference-${item.id}`}>
                                Linked requirement
                            </Label>
                            <select
                                id={`reference-${item.id}`}
                                className={fieldClass}
                                value={item.requirement_id ?? ''}
                                onChange={(e) =>
                                    topics(
                                        form.data.screening_topics.map((row) =>
                                            row.id === item.id
                                                ? {
                                                      ...row,
                                                      requirement_id:
                                                          e.target.value ||
                                                          null,
                                                  }
                                                : row,
                                        ),
                                    )
                                }
                            >
                                <option value="">No linked requirement</option>
                                {form.data.requirements.map((row, i) => (
                                    <option key={row.id} value={row.id}>
                                        {i + 1}.{' '}
                                        {row.text || 'Untitled requirement'}
                                    </option>
                                ))}
                            </select>
                            {error(`screening_topics.${index}.requirement_id`)}
                            <div className="flex flex-wrap items-center gap-2">
                                <label className="mr-auto flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={item.required}
                                        onChange={(e) =>
                                            topics(
                                                form.data.screening_topics.map(
                                                    (row) =>
                                                        row.id === item.id
                                                            ? {
                                                                  ...row,
                                                                  required:
                                                                      e.target
                                                                          .checked,
                                                              }
                                                            : row,
                                                ),
                                            )
                                        }
                                    />
                                    Required topic
                                </label>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={index === 0}
                                    aria-label={`Move topic ${index + 1} up`}
                                    onClick={() =>
                                        topics(
                                            move(
                                                form.data.screening_topics,
                                                index,
                                                -1,
                                            ),
                                        )
                                    }
                                >
                                    ↑
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={
                                        index ===
                                        form.data.screening_topics.length - 1
                                    }
                                    aria-label={`Move topic ${index + 1} down`}
                                    onClick={() =>
                                        topics(
                                            move(
                                                form.data.screening_topics,
                                                index,
                                                1,
                                            ),
                                        )
                                    }
                                >
                                    ↓
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        topics(
                                            form.data.screening_topics.filter(
                                                (row) => row.id !== item.id,
                                            ),
                                        )
                                    }
                                >
                                    Remove
                                </Button>
                            </div>
                            {error(`screening_topics.${index}.required`)}
                        </div>
                    ))}
                    <Button
                        type="button"
                        variant="outline"
                        disabled={form.data.screening_topics.length >= 50}
                        onClick={() =>
                            topics([
                                ...form.data.screening_topics,
                                {
                                    id: crypto.randomUUID(),
                                    text: '',
                                    requirement_id: null,
                                    required: false,
                                    display_order:
                                        form.data.screening_topics.length,
                                },
                            ])
                        }
                    >
                        Add screening topic
                    </Button>
                </section>
                <div className="flex items-center gap-4">
                    <Button type="submit">
                        {form.processing ? 'Saving…' : 'Save position'}
                    </Button>
                    <Link
                        href={
                            position
                                ? `/positions/${position.id}`
                                : '/positions'
                        }
                        className="text-sm underline"
                    >
                        Cancel
                    </Link>
                </div>
            </fieldset>
        </form>
    );
}
