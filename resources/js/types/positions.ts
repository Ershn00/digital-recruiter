export type Requirement = {
    id: string;
    text: string;
    category: 'essential' | 'desirable';
    display_order: number;
};
export type ScreeningTopic = {
    id: string;
    text: string;
    requirement_id: string | null;
    required: boolean;
    display_order: number;
};
export type Position = {
    id: number;
    title: string;
    description: string;
    location: string;
    employment_type: string;
    interview_language: string;
    status: 'draft' | 'active' | 'archived';
    requirements: Requirement[];
    screening_topics: ScreeningTopic[];
    version: number;
};
export type PositionOptions = {
    employmentTypes: string[];
    languages: string[];
};
export const label = (value: string) =>
    value === 'tr'
        ? 'Türkçe'
        : value === 'en'
          ? 'English'
          : value
                .replaceAll('_', ' ')
                .replace(/^./, (letter) => letter.toUpperCase());
