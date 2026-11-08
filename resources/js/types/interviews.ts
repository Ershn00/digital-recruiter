export type InterviewMessage = {
    id: number;
    role: 'assistant' | 'candidate';
    content: string;
};
export type Evaluation = {
    criteria: {
        criterion_id: string;
        state: 'supported' | 'gap' | 'insufficient_evidence';
        rationale: string;
        evidence: { message_id: number; quote: string }[];
    }[];
    strengths: string[];
    gaps: string[];
};
