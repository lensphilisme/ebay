import type { AuditLogRecord, JobLogRecord } from '@/backend/database/models';

export class AutomationStore {
  private auditLogs: AuditLogRecord[] = [];
  private jobLogs: JobLogRecord[] = [];

  addAuditLog(record: Omit<AuditLogRecord, 'id' | 'createdAt'>): AuditLogRecord {
    const saved = { ...record, id: crypto.randomUUID(), createdAt: new Date().toISOString() };
    this.auditLogs.unshift(saved);
    return saved;
  }

  addJobLog(record: Omit<JobLogRecord, 'id' | 'createdAt'>): JobLogRecord {
    const saved = { ...record, id: crypto.randomUUID(), createdAt: new Date().toISOString() };
    this.jobLogs.unshift(saved);
    return saved;
  }

  getAuditLogs(): AuditLogRecord[] {
    return this.auditLogs;
  }

  getJobLogs(): JobLogRecord[] {
    return this.jobLogs;
  }
}

export const automationStore = new AutomationStore();
