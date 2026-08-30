import type { route as ziggyRoute } from 'ziggy-js';

declare global {
    /** Ziggy's named-route helper, published into the page by the @routes directive. */
    const route: typeof ziggyRoute;
}

export {};
