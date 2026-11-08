import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { expect, test } from 'vitest';
import MarkdownContent from '@/components/markdown-content';

function render(content?: string | null) {
    return renderToStaticMarkup(createElement(MarkdownContent, { content }));
}

test('renders headings emphasis paragraphs and nested bullet and numbered lists', () => {
    const html = render(
        '# Senior Laravel Developer\n\n## Aranan Nitelikler\n\n**Laravel** ve *PostgreSQL* deneyimi.\n\nYeni paragraf.\n\n- PHP\n- Veritabanı\n  - PostgreSQL\n\n1. Görüşme\n2. Değerlendirme',
    );
    for (const markup of [
        '<h1>Senior Laravel Developer</h1>',
        '<h2>Aranan Nitelikler</h2>',
        '<strong>Laravel</strong>',
        '<em>PostgreSQL</em>',
        '<p>Yeni paragraf.</p>',
        '<ul>',
        '<ol>',
        '<li>PHP</li>',
        '<li>PostgreSQL</li>',
    ])
        expect(html).toContain(markup);
    expect(html).not.toContain('**Laravel**');
});

test('preserves ordinary soft newlines visually and renders explicit Markdown line breaks', () => {
    const html = render('Birinci satır\nİkinci satır  \nÜçüncü satır');
    expect(html).toContain('whitespace-pre-line');
    expect(html).toContain('Birinci satır\nİkinci satır<br/>\nÜçüncü satır');
});

test.each([undefined, null, '', '   \n\t'])(
    'handles empty description %s gracefully',
    (content) => {
        expect(render(content)).toContain('No job description provided.');
    },
);

test('renders safe web mail relative and fragment links with opener and referrer protection', () => {
    const html = render(
        '[Website](https://example.com/jobs) [Mail](mailto:jobs@example.com) [Local](/positions/2) [Section](#requirements)',
    );
    for (const href of [
        'https://example.com/jobs',
        'mailto:jobs@example.com',
        '/positions/2',
        '#requirements',
    ])
        expect(html).toContain(`href="${href}"`);
    expect(html.match(/rel="noopener noreferrer"/g)).toHaveLength(4);
    expect(html.match(/target="_blank"/g)).toHaveLength(4);
});

test.each([
    'javascript:alert%281%29',
    'JaVaScRiPt:alert%281%29',
    'java&#x73;cript:alert%281%29',
    'vbscript:msgbox%281%29',
    'data:text/html;base64,PHNjcmlwdD4=',
    'file:///etc/passwd',
])('removes unsafe Markdown link destination %s', (url) => {
    const html = render(`[Unsafe link](${url})`);
    expect(html).toContain('Unsafe link');
    expect(html).not.toContain('<a');
    expect(html).not.toContain('href=');
});

test('does not render executable raw HTML event handlers SVG frames or forms', () => {
    const html = render(
        'Safe **content**\n\n<script>alert(1)</script>\n\n<img src="x" onerror="alert(1)">\n\n<svg onload="alert(1)"></svg>\n\n<iframe src="https://evil.example"></iframe>\n\n<form action="https://evil.example"><input autofocus onfocus="alert(1)"></form>\n\n[Safe](https://example.com)',
    );
    expect(html).toContain('<strong>content</strong>');
    expect(html).toContain('href="https://example.com"');
    expect(html).not.toMatch(/<(script|img|svg|iframe|form|input)\b/i);
    expect(html).not.toMatch(/onerror|onload|onfocus|alert\(1\)/i);
});

test('raw HTML links cannot bypass the Markdown URL filter and remote images are not loaded', () => {
    const html = render(
        '<a href="javascript:alert(1)">Raw link</a>\n\n![Tracker](https://evil.example/tracker.png)',
    );
    expect(html).not.toContain('href=');
    expect(html).not.toContain('<img');
    expect(html).not.toContain('javascript:');
});

test('keeps code examples escaped instead of executing their HTML', () => {
    const html = render('`<script>alert(1)</script>`');
    expect(html).toContain(
        '<code>&lt;script&gt;alert(1)&lt;/script&gt;</code>',
    );
    expect(html).not.toContain('<script>');
});
