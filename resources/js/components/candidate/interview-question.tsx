import { LoaderCircle } from 'lucide-react';
import type { Ref } from 'react';
import { Button } from '@/components/ui/button';
import { interviewContent } from '@/lib/candidate-interview';

export type InterviewQuestionProps = {
    language: 'tr' | 'en';
    question: { id: number; content: string };
    questionNumber: number;
    answer: string;
    locked: boolean;
    busy: boolean;
    textareaRef?: Ref<HTMLTextAreaElement>;
    onChange: (value: string) => void;
    onSubmit: () => void;
};

export default function InterviewQuestion({
    language,
    question,
    questionNumber,
    answer,
    locked,
    busy,
    textareaRef,
    onChange,
    onSubmit,
}: InterviewQuestionProps) {
    const copy = interviewContent[language];
    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                if (!busy) onSubmit();
            }}
        >
            <div className="space-y-3">
                <p className="text-sm font-medium text-muted-foreground">
                    {copy.question} {questionNumber}
                </p>
                <h2
                    id="current-question"
                    className="text-lg leading-relaxed font-medium wrap-anywhere whitespace-pre-wrap sm:text-xl"
                >
                    {question.content}
                </h2>
            </div>
            <div className="space-y-2">
                <label htmlFor="answer" className="block text-sm font-medium">
                    {copy.answer}
                </label>
                <textarea
                    ref={textareaRef}
                    id="answer"
                    rows={5}
                    maxLength={6000}
                    required
                    readOnly={locked || busy}
                    value={answer}
                    aria-describedby="current-question answer-help answer-guidance"
                    className="min-h-40 w-full resize-none rounded-lg border border-input bg-background p-3 text-base leading-relaxed read-only:bg-muted/30 focus-visible:outline-2 focus-visible:outline-ring"
                    onChange={(event) => onChange(event.target.value)}
                    onKeyDown={(event) => {
                        if (
                            (event.ctrlKey || event.metaKey) &&
                            event.key === 'Enter' &&
                            !event.nativeEvent.isComposing
                        ) {
                            event.preventDefault();
                            event.currentTarget.form?.requestSubmit();
                        }
                    }}
                />
                <p
                    id="answer-help"
                    className="text-xs leading-relaxed text-muted-foreground"
                >
                    {copy.shortcut} {locked && copy.locked}
                </p>
                <p
                    id="answer-guidance"
                    className="text-xs leading-relaxed text-muted-foreground"
                >
                    {copy.immutableAnswer}
                </p>
            </div>
            <Button
                type="submit"
                size="lg"
                className="min-h-11 w-full sm:w-auto"
                disabled={busy || !answer.trim()}
            >
                {busy ? (
                    <>
                        <LoaderCircle
                            aria-hidden="true"
                            className="motion-safe:animate-spin"
                        />
                        {copy.preparing}
                    </>
                ) : locked ? (
                    copy.retryAnswer
                ) : (
                    copy.send
                )}
            </Button>
        </form>
    );
}
