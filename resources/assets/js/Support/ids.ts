/** Join id or key parts; keeps composed ids out of JSX attribute template literals. */
export const joinParts = (separator: string, ...parts: Array<string | number>): string => parts.join(separator);
