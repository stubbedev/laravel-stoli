/**
 * Compiled with types.ts when the scenario generates per-route URL functions.
 */
import { usersShow, search } from '../resources/js/generated/api.urls';
import { pagesShow } from '../resources/js/generated/pages.urls';

usersShow({ user: 1 });
search();
pagesShow({ slug: 'about' });
// @ts-expect-error a numeric constraint takes no text
usersShow({ user: 'x' });
