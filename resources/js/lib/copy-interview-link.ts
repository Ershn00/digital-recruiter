/** Copy within the click gesture, falling back to a selected field for manual copying. */
export async function copyInterviewLink(
    link: string,
    field: HTMLInputElement | null,
): Promise<boolean> {
    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(link);
            return true;
        }
    } catch {
        // Clipboard permissions and insecure contexts may require a legacy/manual fallback.
    }
    if (!field) return false;
    try {
        field.focus();
        field.select();
        field.setSelectionRange(0, field.value.length);
        return (
            typeof document.execCommand === 'function' &&
            document.execCommand('copy')
        );
    } catch {
        return false;
    }
}
