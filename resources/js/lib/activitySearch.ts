export function hasSearchChanges(
    draft: string,
    submitted: string | null,
    received: string,
): boolean {
    return draft.trim() !== (submitted ?? received).trim();
}

export function reconcileSearchDraft(
    draft: string,
    submitted: string | null,
    received: string,
): string {
    return submitted !== null && received === submitted ? draft : received;
}

export function createSearchDebounce(search: () => void) {
    let timer: ReturnType<typeof setTimeout> | null = null;
    const cancel = () => {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    };

    return {
        cancel,
        onNavigationStart: (event: {
            detail: { visit: { async: boolean } };
        }) => {
            if (!event.detail.visit.async) {
                cancel();
            }
        },
        schedule: () => {
            cancel();
            timer = setTimeout(() => {
                timer = null;
                search();
            }, 350);
        },
    };
}
