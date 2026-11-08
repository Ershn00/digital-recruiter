import { Head } from '@inertiajs/react';
import { Check, CircleCheck } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';

const content = {
    tr: {
        heading: 'Mülakatınız tamamlandı!',
        description: (title: string) =>
            `${title} pozisyonu için AI destekli ön görüşmenizi başarıyla tamamladınız.`,
        confirmations: [
            'Yanıtlarınız başarıyla kaydedildi.',
            'Görüşmeniz işe alım ekibi tarafından değerlendirilecek.',
        ],
        closing: 'Katılımınız için teşekkür ederiz.',
        close: 'Sayfayı Kapat',
        safeToClose: 'Bu sekmeyi güvenle kapatabilirsiniz.',
    },
    en: {
        heading: 'Your interview is complete!',
        description: (title: string) =>
            `You have successfully completed your AI-assisted preliminary interview for the ${title} position.`,
        confirmations: [
            'Your answers have been saved successfully.',
            'Your interview will be reviewed by the recruitment team.',
        ],
        closing: 'Thank you for participating.',
        close: 'Close Page',
        safeToClose: 'You can safely close this tab.',
    },
};

export default function Completed({
    interview,
}: {
    interview: { title: string; language: 'tr' | 'en' };
}) {
    const copy = content[interview.language];
    const [showCloseHint, setShowCloseHint] = useState(false);

    function closePage() {
        // Only the browser can decide whether this tab is script-closable.
        // If it stays open (or close throws), the hint remains available.
        setShowCloseHint(true);
        try {
            window.close();
        } catch {
            // The candidate can always close the tab manually.
        }
    }

    return (
        <main
            lang={interview.language}
            className="flex min-h-svh items-center justify-center bg-background px-4 py-10 sm:px-6"
        >
            <Head title={copy.heading} />
            <section
                aria-labelledby="completion-heading"
                className="w-full max-w-xl rounded-2xl border bg-card p-6 text-center text-card-foreground shadow-sm sm:p-10"
            >
                <div className="mx-auto mb-6 flex size-20 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <CircleCheck
                        aria-hidden="true"
                        className="size-10"
                        strokeWidth={1.5}
                    />
                </div>
                <h1
                    id="completion-heading"
                    className="text-2xl font-semibold tracking-tight sm:text-3xl"
                >
                    {copy.heading}
                </h1>
                <p className="mt-4 text-base leading-relaxed wrap-anywhere text-muted-foreground">
                    {copy.description(interview.title)}
                </p>
                <ul className="my-8 space-y-4 rounded-xl bg-muted/50 p-4 text-start text-sm sm:p-5">
                    {copy.confirmations.map((confirmation) => (
                        <li
                            key={confirmation}
                            className="flex items-start gap-3"
                        >
                            <Check
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0 text-primary"
                            />
                            <span>{confirmation}</span>
                        </li>
                    ))}
                </ul>
                <p className="mb-6 text-sm text-muted-foreground">
                    {copy.closing}
                </p>
                <Button
                    type="button"
                    size="lg"
                    className="min-h-11 w-full sm:w-auto sm:min-w-48"
                    onClick={closePage}
                    aria-describedby={showCloseHint ? 'close-hint' : undefined}
                >
                    {copy.close}
                </Button>
                <p
                    id="close-hint"
                    role="status"
                    aria-live="polite"
                    className="mt-4 text-sm text-muted-foreground"
                >
                    {showCloseHint ? copy.safeToClose : ''}
                </p>
            </section>
        </main>
    );
}
