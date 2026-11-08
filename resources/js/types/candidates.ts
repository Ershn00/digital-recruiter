export type CandidateApplication = {
    id: number;
    name: string;
    archived_at: string | null;
    can_delete: boolean;
    interview: { id: number; status: string } | null;
    interview_url: string | null;
};
