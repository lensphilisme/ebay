export interface AuditLogRecord {
  id: string;
  actor: string;
  action: string;
  targetType: string;
  targetId?: string;
  before?: unknown;
  after?: unknown;
  reason: string;
  createdAt: string;
}

export interface JobLogRecord {
  id: string;
  jobName: string;
  status: 'pending' | 'running' | 'succeeded' | 'failed';
  message: string;
  metadata: Record<string, unknown>;
  createdAt: string;
}
