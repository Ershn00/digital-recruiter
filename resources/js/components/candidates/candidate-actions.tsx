import { useForm } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { CandidateApplication } from '@/types/candidates';

type Mode = 'edit' | 'archive' | 'restore' | 'delete';
const titles = {
    edit: 'Edit Candidate',
    archive: 'Archive Candidate',
    restore: 'Restore Candidate',
    delete: 'Delete Candidate',
};
const descriptions = {
    edit: 'Update the display name only. Interview sessions, answers, evaluations and private links remain unchanged.',
    archive:
        'Hide this candidate from the active list and invalidate their private interview link. Interview history and evaluations are preserved.',
    restore:
        'Return this candidate to the active list. Old interview links stay invalid. Generate a new link from interview detail if the interview is unfinished.',
    delete: 'Permanently delete this unused fictional demo candidate and its empty interview, if any. Candidates with interview history cannot be deleted; archive them instead.',
};

export default function CandidateActions({
    candidate,
}: {
    candidate: CandidateApplication;
}) {
    const [mode, setMode] = useState<Mode | null>(null);
    const [error, setError] = useState('');
    const edit = useForm({ name: candidate.name });
    const mutation = useForm({ confirm_delete: false });
    const busy = edit.processing || mutation.processing;
    const endpoint = `/candidates/${candidate.id}`;
    function open(next: Mode) {
        setError('');
        edit.clearErrors();
        mutation.clearErrors();
        edit.setData('name', candidate.name);
        mutation.setData('confirm_delete', false);
        setMode(next);
    }
    function submit() {
        if (busy || !mode) return;
        const options = {
            preserveScroll: true,
            onSuccess: () => setMode(null),
            onNetworkError: () => {
                setError(
                    'Connection interrupted. Refresh the candidate list to check whether the action completed.',
                );
                return false;
            },
            onHttpException: () => {
                setError(
                    'The action could not be completed. Refresh the candidate list and try again.',
                );
                return false;
            },
        };
        if (mode === 'edit') edit.patch(endpoint, options);
        else if (mode === 'delete') {
            if (candidate.can_delete && mutation.data.confirm_delete)
                mutation.delete(endpoint, options);
        } else mutation.post(`${endpoint}/${mode}`, options);
    }
    return (
        <>
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label={`Actions for ${candidate.name}`}
                    >
                        <MoreHorizontal aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={() => open('edit')}>
                        Edit Candidate
                    </DropdownMenuItem>
                    {candidate.archived_at ? (
                        <DropdownMenuItem onSelect={() => open('restore')}>
                            Restore Candidate
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem onSelect={() => open('archive')}>
                            Archive Candidate
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        disabled={!candidate.can_delete}
                        onSelect={() => open('delete')}
                    >
                        Delete Candidate
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <Dialog
                open={mode !== null}
                onOpenChange={(value) => {
                    if (!value && !busy) setMode(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {mode ? titles[mode] : 'Candidate actions'}
                        </DialogTitle>
                        <DialogDescription>
                            {mode ? descriptions[mode] : ''}
                        </DialogDescription>
                    </DialogHeader>
                    <p className="text-sm font-medium">{candidate.name}</p>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            submit();
                        }}
                    >
                        {mode === 'edit' && (
                            <div className="space-y-2">
                                <label
                                    htmlFor={`candidate-name-${candidate.id}`}
                                    className="block text-sm font-medium"
                                >
                                    Display name
                                </label>
                                <input
                                    id={`candidate-name-${candidate.id}`}
                                    className="w-full rounded-md border p-2"
                                    value={edit.data.name}
                                    onChange={(event) =>
                                        edit.setData('name', event.target.value)
                                    }
                                    required
                                    maxLength={200}
                                    disabled={busy}
                                />
                            </div>
                        )}
                        {mode === 'delete' && (
                            <label className="flex items-start gap-3 text-sm">
                                <Checkbox
                                    checked={mutation.data.confirm_delete}
                                    onCheckedChange={(checked) =>
                                        mutation.setData(
                                            'confirm_delete',
                                            checked === true,
                                        )
                                    }
                                    disabled={busy}
                                    required
                                />
                                <span>
                                    I understand this permanently deletes this
                                    unused fictional candidate.
                                </span>
                            </label>
                        )}
                        {[
                            ...Object.values(edit.errors),
                            ...Object.values(mutation.errors),
                            ...(error ? [error] : []),
                        ].map((message, index) => (
                            <p
                                key={index}
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {message}
                            </p>
                        ))}
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                                onClick={() => setMode(null)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                variant={
                                    mode === 'delete'
                                        ? 'destructive'
                                        : 'default'
                                }
                                disabled={
                                    busy ||
                                    (mode === 'delete' &&
                                        (!candidate.can_delete ||
                                            !mutation.data.confirm_delete))
                                }
                            >
                                {busy
                                    ? 'Saving…'
                                    : mode
                                      ? titles[mode]
                                      : 'Save'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
