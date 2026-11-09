export { AmnesicMailClient, MAX_PAGE_SIZE, MAX_WAIT_SECONDS } from './client.js';
export {
  AmnesicMailError,
  ApiError,
  AuthenticationError,
  BadRequestError,
  ConnectionError,
  NotFoundError,
  PermissionDeniedError,
  RequestTimeoutError,
  WaitTimeoutError,
} from './errors.js';
export type { ApiErrorDetails } from './errors.js';
export { extractCode, extractLink, extractLinks } from './extract.js';
export type { ExtractCodeOptions, ExtractLinksOptions, ExtractSource, MessageContent } from './extract.js';
export type {
  AddressInfo,
  AmnesicMailClientOptions,
  CheckpointOptions,
  DateInput,
  DeleteAddressMessagesResult,
  DeleteMessageResult,
  EmailAddress,
  IterateMessagesOptions,
  ListMessagesOptions,
  Message,
  MessageList,
  MessagePart,
  MessageSummary,
  PartKind,
  PartStatus,
  RequestOptions,
  WaitForMessageOptions,
} from './types.js';
export { VERSION } from './version.js';
