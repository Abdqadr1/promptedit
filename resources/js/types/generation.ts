export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Generation = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    status: GenerationStatus;
    is_terminal: boolean;
    error_code: string | null;
    error_message: string | null;
    estimated_min_charge: string;
    estimated_max_charge: string;
    charge: string | null;
    charge_exact: string | null;
    image_src: string | null;
    image_stored: boolean;
    image_url_expires_at: string | null;
    provider_id: string | null;
    usage: Record<string, number> | null;
    /** Only present for admins. */
    provider_cost?: string;
    created_at: string;
    submitted_at: string | null;
    completed_at: string | null;
};

export type PricingCatalog = {
    rates: Record<string, string>;
    tokens: {
        characters_per_text_token: number;
        text_token_variance: number;
        per_reference_image: number;
        output: Record<string, Record<string, number>>;
        reasoning: Record<string, { min: number; max: number }>;
    };
    markup_percent: number;
};

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: { current_page: number; last_page: number; total: number };
};
