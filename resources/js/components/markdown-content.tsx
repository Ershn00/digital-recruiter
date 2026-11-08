import ReactMarkdown, { defaultUrlTransform } from 'react-markdown';
import { cn } from '@/lib/utils';

function safeUrl(url: string): string {
    const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(url)?.[1]?.toLowerCase();
    if (scheme && !['http', 'https', 'mailto'].includes(scheme)) return '';
    return defaultUrlTransform(url);
}

export default function MarkdownContent({
    content,
    className,
    emptyMessage = 'No job description provided.',
}: {
    content?: string | null;
    className?: string;
    emptyMessage?: string;
}) {
    if (!content?.trim())
        return <p className="text-sm text-muted-foreground">{emptyMessage}</p>;

    return (
        <div
            className={cn(
                'text-sm leading-relaxed wrap-anywhere [&_blockquote]:border-l-2 [&_blockquote]:pl-4 [&_blockquote]:text-muted-foreground [&_code]:font-mono [&_h1]:mt-6 [&_h1]:mb-3 [&_h1]:text-xl [&_h1]:font-semibold [&_h2]:mt-5 [&_h2]:mb-3 [&_h2]:text-lg [&_h2]:font-semibold [&_h3]:mt-4 [&_h3]:mb-2 [&_h3]:text-base [&_h3]:font-semibold [&_h4]:mt-4 [&_h4]:font-semibold [&_h5]:mt-3 [&_h5]:font-semibold [&_h6]:mt-3 [&_h6]:font-semibold [&_hr]:my-4 [&_li]:my-1 [&_ol]:my-3 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:mb-3 [&_p]:whitespace-pre-line [&_pre]:my-3 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-muted [&_pre]:p-3 [&_strong]:font-semibold [&_ul]:my-3 [&_ul]:list-disc [&_ul]:pl-6 [&>:first-child]:mt-0 [&>:last-child]:mb-0',
                className,
            )}
        >
            <ReactMarkdown
                skipHtml
                allowedElements={[
                    'h1',
                    'h2',
                    'h3',
                    'h4',
                    'h5',
                    'h6',
                    'p',
                    'strong',
                    'em',
                    'ul',
                    'ol',
                    'li',
                    'br',
                    'a',
                    'blockquote',
                    'pre',
                    'code',
                    'hr',
                ]}
                urlTransform={safeUrl}
                components={{
                    a: ({ href, title, children }) =>
                        href ? (
                            <a
                                href={href}
                                title={title}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-primary underline underline-offset-4 hover:opacity-80"
                            >
                                {children}
                            </a>
                        ) : (
                            <span>{children}</span>
                        ),
                }}
            >
                {content}
            </ReactMarkdown>
        </div>
    );
}
