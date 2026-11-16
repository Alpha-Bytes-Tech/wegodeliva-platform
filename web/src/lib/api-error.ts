/**
 * Error format shared by every backend endpoint (docs/spec/14-api-behaviour.md §14.1):
 * { "error": { "code": "...", "message": "...", "fields": { "email": ["..."] } } }
 */
export type ApiError = {
  code: string;
  message: string;
  fields: Record<string, string[]>;
};

export const GENERIC_ERROR: ApiError = {
  code: "server_error",
  message: "Something went wrong. Please try again.",
  fields: {},
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

/** Turns any response body into an ApiError, falling back to a generic message. */
export function parseApiError(body: unknown): ApiError {
  if (!isRecord(body) || !isRecord(body.error)) {
    return GENERIC_ERROR;
  }
  const { code, message, fields } = body.error;
  const parsedFields: Record<string, string[]> = {};
  if (isRecord(fields)) {
    for (const [key, value] of Object.entries(fields)) {
      if (Array.isArray(value)) {
        parsedFields[key] = value.filter((v): v is string => typeof v === "string");
      }
    }
  }
  return {
    code: typeof code === "string" ? code : GENERIC_ERROR.code,
    message: typeof message === "string" ? message : GENERIC_ERROR.message,
    fields: parsedFields,
  };
}
