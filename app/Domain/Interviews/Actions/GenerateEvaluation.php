<?php

namespace App\Domain\Interviews\Actions;

use App\Domain\Interviews\Services\OpenAiInterview;
use App\Models\InterviewSession;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

class GenerateEvaluation
{
    public function handle(InterviewSession $interview): void
    {
        $claim = (string) Str::uuid();
        $work = DB::transaction(function () use ($interview, $claim) {
            $session = InterviewSession::query()->lockForUpdate()->findOrFail($interview->id);
            if ($session->status !== 'completed') {
                throw ValidationException::withMessages(['interview' => 'Complete the interview before evaluating.']);
            }
            if ($session->evaluation()->exists()) {
                return null;
            }
            if ($session->processing_id && $session->processing_started_at?->gt(now()->subSeconds(90))) {
                throw ValidationException::withMessages(['interview' => 'Evaluation is running. Wait and refresh.']);
            }
            $session->update(['processing_id' => $claim, 'processing_started_at' => now()]);

            return $session;
        });
        if (! $work) {
            return;
        }
        $stage = 'generation';
        try {
            $result = app(OpenAiInterview::class)->evaluate($work);
            $stage = 'persistence';
            DB::transaction(function () use ($work, $claim, $result) {
                $session = InterviewSession::query()->lockForUpdate()->findOrFail($work->id);
                if ($session->processing_id !== $claim) {
                    return;
                }
                $session->evaluation()->create(['result' => $result, 'model' => config('services.openai.model')]);
                $session->update(['processing_id' => null, 'processing_started_at' => null]);
            });
        } catch (Throwable $exception) {
            // Never log exception messages/bodies: they may contain transcript text or credentials.
            $category = match (true) {
                $exception instanceof ValidationException => 'schema_validation',
                $exception instanceof ConnectionException => 'connection',
                $exception instanceof RequestException => 'http',
                $exception instanceof JsonException => 'json_parsing',
                $exception instanceof QueryException => 'database',
                $exception->getMessage() === 'Invalid evidence reference.' => 'evidence_reference',
                $exception->getMessage() === 'Unsupported assessment.' => 'unsupported_assessment',
                $exception->getMessage() === 'Incomplete AI response.' => 'incomplete_response',
                $exception->getMessage() === 'Missing AI output.' => 'missing_output',
                $exception->getMessage() === 'OpenAI is not configured.' => 'configuration',
                default => 'unexpected',
            };
            Log::warning('Interview evaluation failed.', [
                'interview_id' => $work->id,
                'stage' => $stage,
                'category' => $category,
                'http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
                'validation_rules' => $exception instanceof ValidationException
                    ? array_values(array_unique(array_merge([], ...array_map(array_keys(...), array_values($exception->validator->failed())))))
                    : [],
            ]);
            InterviewSession::whereKey($work->id)->where('processing_id', $claim)->update(['processing_id' => null, 'processing_started_at' => null]);
            throw ValidationException::withMessages(['interview' => 'Evaluation failed or evidence could not be verified. Transcript is saved; retry evaluation.']);
        }
    }
}
