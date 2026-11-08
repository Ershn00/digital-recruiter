export type CandidateInterviewState = {
    id: number;
    title: string;
    status: string;
    language: 'tr' | 'en';
    current_question: { id: number; content: string } | null;
    question_number: number;
    started: boolean;
    answer_saved: boolean;
    last_submission_id: string | null;
    completed_topics: number;
    total_topics: number;
};

export type InterviewDraft = {
    questionId: number;
    content: string;
    submissionId: string;
    submitted: boolean;
};

// Browser storage is best-effort and scoped to this tab. Never store the access token.
export function readInterviewDraft(key: string): InterviewDraft | null {
    try {
        const value: unknown = JSON.parse(
            window.sessionStorage.getItem(key) ?? 'null',
        );
        if (
            value &&
            typeof value === 'object' &&
            'questionId' in value &&
            typeof value.questionId === 'number' &&
            'content' in value &&
            typeof value.content === 'string' &&
            value.content.length <= 6000 &&
            'submissionId' in value &&
            typeof value.submissionId === 'string' &&
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
                value.submissionId,
            ) &&
            'submitted' in value &&
            typeof value.submitted === 'boolean'
        ) {
            return {
                questionId: value.questionId,
                content: value.content,
                submissionId: value.submissionId,
                submitted: value.submitted,
            };
        }
    } catch {
        // A disabled storage API must not prevent participation.
    }
    return null;
}

export function storeInterviewDraft(
    key: string,
    draft: InterviewDraft | null,
): boolean {
    try {
        if (draft) window.sessionStorage.setItem(key, JSON.stringify(draft));
        else window.sessionStorage.removeItem(key);
        return true;
    } catch {
        return false;
    }
}

export const interviewContent = {
    tr: {
        interview: 'AI destekli ön görüşme',
        introduction:
            'Bu görüşme AI desteğiyle gerçekleştirilecektir. Yanıtlarınız kaydedilecek ve işe alım ekibi tarafından incelenecektir. Otomatik işe alım kararı verilmeyecektir.',
        start: 'Mülakata Başla',
        question: 'Soru',
        answer: 'Yanıtınız',
        send: 'Yanıtı Gönder',
        preparing: 'Bir sonraki soru hazırlanıyor...',
        saved: 'Yanıtınız kaydedildi.',
        pending: 'Devam ettiğinizde sıradaki soru hazırlanacak.',
        resume: 'Görüşmeye Devam Et',
        retryAnswer: 'Yanıtı yeniden gönder',
        check: 'Tekrar Dene',
        checking: 'Görüşme durumu kontrol ediliyor...',
        shortcut:
            'Ctrl/Cmd + Enter ile gönderin. Yeni satır için Enter kullanın.',
        topics: (done: number, total: number) =>
            `${done} / ${total} konu tamamlandı`,
        error: 'İşlem tamamlanamadı. Kaydedilmiş yanıtlarınız güvende. Durumu kontrol edin veya yeniden deneyin.',
        networkError:
            'Bağlantı kesildi. Yanıtınız bu sekmede korunuyor. Durumu kontrol edin veya aynı yanıtı yeniden gönderin.',
        httpError:
            'İşlem tamamlanamadı. Durumu kontrol edin. Bağlantının süresi dolduysa işe alım uzmanından yeni bağlantı isteyin.',
        answerError: 'Lütfen en fazla 6000 karakterlik bir yanıt yazın.',
        locked: 'Gönderilen yanıt korunuyor. Yeniden göndermeden önce durumu kontrol edebilirsiniz.',
        stale: 'Görüşme başka bir sekmede ilerlemiş olabilir. Bu yanıtın kaydedildiği doğrulanamadı. Metniniz korunuyor; kopyalayabilir veya yeni soruya geçmek için silebilirsiniz.',
        unconfirmed: 'Kaydı doğrulanmamış yanıtınız',
        discard: 'Yerel taslağı sil ve devam et',
        confirmDiscard:
            'Bu yanıt kaydedilmemiş olabilir. Yerel taslağı silmek istiyor musunuz?',
        storageError:
            'Bu tarayıcı taslağı cihazda saklayamıyor. Yanıtın kaydedildiği doğrulanana kadar bu sekmeyi açık tutun.',
        progress: 'Mülakat ilerlemesi',
        immutableAnswer: 'Yanıtınızı gönderdikten sonra değiştiremezsiniz.',
    },
    en: {
        interview: 'AI-assisted preliminary interview',
        introduction:
            'This interview is assisted by AI. Your answers will be saved and reviewed by the recruitment team. No automatic hiring decision will be made.',
        start: 'Start Interview',
        question: 'Question',
        answer: 'Your answer',
        send: 'Submit Answer',
        preparing: 'Preparing the next question...',
        saved: 'Your answer has been saved.',
        pending: 'Continue to prepare the next question.',
        resume: 'Continue Interview',
        retryAnswer: 'Retry sending answer',
        check: 'Try Again',
        checking: 'Checking interview status...',
        shortcut: 'Press Ctrl/Cmd + Enter to submit. Use Enter for a new line.',
        topics: (done: number, total: number) =>
            `${done} / ${total} topics completed`,
        error: 'The request could not be completed. Saved answers are safe. Check the status or try again.',
        networkError:
            'The connection was interrupted. Your answer is retained in this tab. Check the status or resend the same answer.',
        httpError:
            'The request could not be completed. Check the status. If your link has expired, ask the recruiter for a new one.',
        answerError: 'Please enter an answer of up to 6000 characters.',
        locked: 'Your submitted answer is retained. You can check its status before resending it.',
        stale: 'The interview may have advanced in another tab. We could not confirm this answer was saved. Your text is retained; copy it or discard it to continue to the current question.',
        unconfirmed: 'Your unconfirmed answer',
        discard: 'Discard local draft and continue',
        confirmDiscard:
            'This answer may not have been saved. Discard the local draft?',
        storageError:
            'This browser cannot store a draft on your device. Keep this tab open until your answer is confirmed saved.',
        progress: 'Interview progress',
        immutableAnswer: 'You cannot edit your answer after submitting it.',
    },
};
