/**
 * How many times longer than a workstation this machine may take. Software WebGL on a hosted runner draws the
 * terrain several times slower, so the check workflow sets PHONICS_TEST_SLOW. Unset, every limit keeps the value
 * written in the test.
 */
export const slowdown = Math.max(1, Number(process.env.PHONICS_TEST_SLOW) || 1);

/**
 * Scale a time limit for the machine running the tests.
 * @param {number} milliseconds Limit as written for a workstation.
 * @returns {number} Limit for this machine.
 */
export const slow = milliseconds => milliseconds * slowdown;
