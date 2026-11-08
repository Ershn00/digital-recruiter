<?php

namespace App\Domain\Interviews\Actions;

use App\Domain\Interviews\Services\OpenAiInterview;
use App\Models\InterviewSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdvanceInterview
{
    /** @param array{content: string, submission_id: string, reply_to: int}|null $answer */
    public function handle(InterviewSession $interview, string $operation, ?array $answer = null): void
    {
        if (! in_array($operation, ['answer', 'resume'], true)) {
            throw ValidationException::withMessages(['interview' => 'Interviews complete automatically after all screening topics.']);
        }
        $claim = (string) Str::uuid();
        $work = DB::transaction(function () use ($interview, $answer, $claim) {
            $session = InterviewSession::query()->lockForUpdate()->findOrFail($interview->id);
            // Recheck a token resolved before this lock: a recruiter may have rotated it meanwhile.
            abort_unless(hash_equals($session->access_token_hash, $interview->access_token_hash) && $session->expires_at->isFuture() && $session->application->archived_at === null, 404);
            if ($session->processing_id && $session->processing_started_at?->gt(now()->subSeconds(90))) {
                throw ValidationException::withMessages(['interview' => 'İşlem sürüyor. Biraz bekleyip sayfayı yenileyin.']);
            }
            if ($session->status === 'completed') {
                return null;
            }
            if ($answer) {
                $existing = $session->messages()->where('submission_id', $answer['submission_id'])->first();
                if ($existing) {
                    if ($existing->content !== $answer['content']) {
                        throw ValidationException::withMessages(['interview' => 'Bu gönderim kimliği daha önce kullanıldı.']);
                    }
                    // Exact retries must not create a new answer or advance a second time.
                    if ($session->messages()->where('id', '>', $existing->id)->exists()) {
                        return null;
                    }
                } else {
                    $last = $session->messages()->reorder()->latest('id')->first();
                    if ($session->status !== 'active' || ! $last || $last->role !== 'assistant'
                        || $last->id !== (int) $answer['reply_to']) {
                        throw ValidationException::withMessages(['interview' => 'Yanıt kaydedilmedi. Güncel soruyu görmek için sayfayı yenileyin.']);
                    }
                    $session->messages()->create(['role' => 'candidate', 'content' => $answer['content'], 'submission_id' => $answer['submission_id']]);
                    if ($session->follow_up_used) {
                        $session->question_index++;
                        $session->follow_up_used = false;
                    } else {
                        $session->follow_up_used = true;
                    }
                }
            }
            if ($session->question_index >= count($session->criteria_snapshot['screening_topics'])) {
                $session->status = 'completed';
                $session->completed_at = now();
                $session->processing_id = null;
                $session->save();

                return null;
            }
            $last = $session->messages()->reorder()->latest('id')->first();
            if ($last && $last->role === 'assistant') {
                return null;
            }
            $session->processing_id = $claim;
            $session->processing_started_at = now();
            $session->save();

            return $session;
        });
        if (! $work) {
            return;
        }
        try {
            $result = app(OpenAiInterview::class)->question($work);
            DB::transaction(function () use ($work, $claim, $result) {
                $session = InterviewSession::query()->lockForUpdate()->findOrFail($work->id);
                if ($session->processing_id !== $claim || $session->status !== 'active') {
                    return;
                }
                $session->messages()->create(['role' => 'assistant', 'content' => $result['question']]);
                $session->update(['processing_id' => null, 'processing_started_at' => null]);
            });
        } catch (Throwable $exception) {
            InterviewSession::whereKey($work->id)->where('processing_id', $claim)->update(['processing_id' => null, 'processing_started_at' => null]);
            throw ValidationException::withMessages(['interview' => 'AI yanıtı alınamadı. Kaydedilen yanıtlarınız güvende. “Devam et / yeniden dene” düğmesini kullanın.']);
        }
    }
}
