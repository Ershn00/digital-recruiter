import { webcrypto } from 'node:crypto';
import { afterEach, expect, test, vi } from 'vitest';
import { createUuid } from './uuid';

const uuidV4 =
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

afterEach(() => vi.unstubAllGlobals());

test('uses native UUID generation when available', () => {
    const id = 'e7f8c76a-b518-4d31-b8c2-9ea6e7249c03';
    const randomUUID = vi.fn(() => id);
    vi.stubGlobal('crypto', { randomUUID });
    expect(createUuid()).toBe(id);
    expect(randomUUID).toHaveBeenCalledOnce();
});

test('generates unique UUID v4 identifiers without the secure-context API', () => {
    vi.stubGlobal('crypto', {
        getRandomValues: webcrypto.getRandomValues.bind(webcrypto),
    });
    const ids = Array.from({ length: 1000 }, () => createUuid());
    expect(ids.every((id) => uuidV4.test(id))).toBe(true);
    expect(new Set(ids).size).toBe(ids.length);
});

test('sets UUID version and variant bits and preserves leading zeroes', () => {
    vi.stubGlobal('crypto', {
        getRandomValues: (bytes: Uint8Array) => bytes.fill(0),
    });
    expect(createUuid()).toBe('00000000-0000-4000-8000-000000000000');
});
