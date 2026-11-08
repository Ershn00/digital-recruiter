import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { expect, test, vi } from 'vitest';
import Completed from '../pages/candidate/completed';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));

test.each([
    {
        language: 'tr' as const,
        heading: 'Mülakatınız tamamlandı!',
        description:
            'Senior Laravel Developer pozisyonu için AI destekli ön görüşmenizi başarıyla tamamladınız.',
        confirmations: [
            'Yanıtlarınız başarıyla kaydedildi.',
            'Görüşmeniz işe alım ekibi tarafından değerlendirilecek.',
        ],
        closing: 'Katılımınız için teşekkür ederiz.',
        close: 'Sayfayı Kapat',
    },
    {
        language: 'en' as const,
        heading: 'Your interview is complete!',
        description:
            'You have successfully completed your AI-assisted preliminary interview for the Senior Laravel Developer position.',
        confirmations: [
            'Your answers have been saved successfully.',
            'Your interview will be reviewed by the recruitment team.',
        ],
        closing: 'Thank you for participating.',
        close: 'Close Page',
    },
])(
    'renders the dedicated $language completion screen without chat controls',
    (copy) => {
        const html = renderToStaticMarkup(
            createElement(Completed, {
                interview: {
                    title: 'Senior Laravel Developer',
                    language: copy.language,
                },
            }),
        );
        for (const text of [
            copy.heading,
            copy.description,
            ...copy.confirmations,
            copy.closing,
            copy.close,
        ]) {
            expect(html).toContain(text);
        }
        expect(html).toContain(`lang="${copy.language}"`);
        expect(html).toContain('role="status"');
        expect(html.match(/<button\b/g)).toHaveLength(1);
        expect(html).not.toMatch(/<(form|input|textarea|article|a)\b/);
        expect(html).not.toContain('Yanıtı gönder');
        expect(html).not.toContain('Devam et / yeniden dene');
        expect(html).not.toContain('Mülakatı tamamla');
    },
);

test('uses the supplied position title in completion content', () => {
    const html = renderToStaticMarkup(
        createElement(Completed, {
            interview: { title: 'PHP Engineer', language: 'tr' },
        }),
    );
    expect(html).toContain('PHP Engineer pozisyonu için');
    expect(html).not.toContain('Senior Laravel Developer');
});
