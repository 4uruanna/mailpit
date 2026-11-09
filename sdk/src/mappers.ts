import type {
  AddressInfo,
  EmailAddress,
  Message,
  MessageList,
  MessagePart,
  MessageSummary,
  PartKind,
  PartStatus,
} from './types.js';

/*
 * Réponses de l'API telles qu'elles arrivent (snake_case, dates en texte ISO 8601 UTC), d'après
 * MessageRepository::present() et les actions de api/src/Action/Api/.
 */

export interface RawAddress {
  name: string;
  address: string;
}

export interface RawPart {
  part_id: string;
  kind: PartKind;
  file_name: string;
  content_type: string;
  content_id: string;
  size: number;
  status: PartStatus;
}

export interface RawMessageSummary {
  id: string;
  message_id: string;
  from: RawAddress | null;
  to: RawAddress[];
  cc: RawAddress[];
  bcc: RawAddress[];
  reply_to: RawAddress[];
  subject: string;
  snippet: string;
  return_path: string;
  tags: string[];
  size: number;
  attachments: number;
  username: string | null;
  label: string | null;
  date: string | null;
  created_at: string;
  received_at: string;
  complete: boolean;
  truncated: boolean;
  parts: RawPart[];
}

export interface RawMessage extends RawMessageSummary {
  text: string | null;
  html: string | null;
  headers: Record<string, string[]>;
}

export interface RawMessageList {
  total: number;
  count: number;
  limit: number;
  offset: number;
  page: number;
  waited_ms?: number;
  messages: RawMessageSummary[];
}

export interface RawAddressList {
  count: number;
  addresses: { address: string; messages: number }[];
}

export interface RawDeleteMessage {
  id: string;
  deleted: boolean;
}

export interface RawDeleteAddressMessages {
  address: string;
  deleted: number;
}

function toAddress(raw: RawAddress): EmailAddress {
  return { name: raw.name ?? '', address: raw.address ?? '' };
}

function toAddresses(raw: RawAddress[] | null | undefined): EmailAddress[] {
  return (raw ?? []).map(toAddress);
}

function toPart(raw: RawPart): MessagePart {
  return {
    partId: raw.part_id,
    kind: raw.kind,
    fileName: raw.file_name,
    contentType: raw.content_type,
    contentId: raw.content_id,
    size: raw.size,
    status: raw.status,
  };
}

function toDate(value: string): Date;
function toDate(value: string | null): Date | null;
function toDate(value: string | null): Date | null {
  return value === null ? null : new Date(value);
}

export function toMessageSummary(raw: RawMessageSummary): MessageSummary {
  return {
    id: raw.id,
    messageId: raw.message_id,
    from: raw.from === null ? null : toAddress(raw.from),
    to: toAddresses(raw.to),
    cc: toAddresses(raw.cc),
    bcc: toAddresses(raw.bcc),
    replyTo: toAddresses(raw.reply_to),
    subject: raw.subject,
    snippet: raw.snippet,
    returnPath: raw.return_path,
    tags: raw.tags ?? [],
    size: raw.size,
    attachments: raw.attachments,
    username: raw.username,
    label: raw.label,
    date: toDate(raw.date),
    createdAt: toDate(raw.created_at),
    receivedAt: toDate(raw.received_at),
    complete: raw.complete,
    truncated: raw.truncated,
    parts: (raw.parts ?? []).map(toPart),
  };
}

export function toMessage(raw: RawMessage): Message {
  return {
    ...toMessageSummary(raw),
    text: raw.text,
    html: raw.html,
    headers: raw.headers ?? {},
  };
}

export function toMessageList(raw: RawMessageList): MessageList {
  return {
    total: raw.total,
    count: raw.count,
    limit: raw.limit,
    offset: raw.offset,
    page: raw.page,
    waitedMs: raw.waited_ms ?? null,
    messages: raw.messages.map(toMessageSummary),
  };
}

export function toAddressList(raw: RawAddressList): AddressInfo[] {
  return raw.addresses.map((entry) => ({ address: entry.address, messageCount: entry.messages }));
}
