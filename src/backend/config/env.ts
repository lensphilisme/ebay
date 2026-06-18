import 'dotenv/config';
import { existsSync, readFileSync } from 'node:fs';

export interface AppConfig {
  apiPort: number;
  encryptionSecret: string;
  ebay: {
    clientId: string;
    clientSecret: string;
    devId: string;
    redirectUri: string;
    marketplaceId: string;
    environment: 'sandbox' | 'production';
    apiBaseUrl: string;
    ruName: string;
    userRefreshToken?: string;
    userAccessToken?: string;
    appToken?: string;
  };
  cj: {
    openApiBase: string;
    accessToken?: string;
    email?: string;
    password?: string;
    apiKey?: string;
  };
  ai: {
    provider: string;
    apiKey?: string;
    model?: string;
    openaiApiKey?: string;
    openaiModel?: string;
    geminiApiKey?: string;
    geminiModel?: string;
  };
}

function envValue(key: string): string | undefined {
  const values: string[] = [];
  if (process.env[key] != null) values.push(process.env[key] ?? '');

  if (existsSync('.env')) {
    const lines = readFileSync('.env', 'utf8').split(/\r?\n/);
    for (const line of lines) {
      const match = line.match(/^([A-Za-z0-9_]+)=(.*)$/);
      if (!match || match[1] !== key) continue;
      values.push(match[2].trim().replace(/^"|"$/g, ''));
    }
  }

  const nonEmpty = values.map((value) => value.trim()).filter(Boolean);
  return nonEmpty.at(-1);
}

export function getConfig(): AppConfig {
  if (envValue('EBAY_DISABLE_SSL_VERIFY') === 'true' || envValue('NODE_TLS_REJECT_UNAUTHORIZED') === '0') {
    process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
  }

  const ebayEnvironment = envValue('EBAY_ENVIRONMENT') === 'sandbox' ? 'sandbox' : 'production';

  return {
    apiPort: Number(envValue('AUTOMATION_API_PORT') ?? 8787),
    encryptionSecret: envValue('AUTOMATION_ENCRYPTION_SECRET') ?? 'local-dev-change-me',
    ebay: {
      clientId: envValue('EBAY_CLIENT_ID') ?? '',
      clientSecret: envValue('EBAY_CLIENT_SECRET') ?? '',
      devId: envValue('EBAY_DEV_ID') ?? '',
      redirectUri: envValue('EBAY_REDIRECT_URI') ?? `${envValue('APP_BASE_URL') ?? 'http://localhost:8787'}/auth/ebay/callback`,
      marketplaceId: envValue('EBAY_MARKETPLACE_ID') ?? 'EBAY_US',
      environment: ebayEnvironment,
      apiBaseUrl: envValue('EBAY_API_BASE') ?? (ebayEnvironment === 'sandbox' ? 'https://api.sandbox.ebay.com' : 'https://api.ebay.com'),
      ruName: envValue('EBAY_RUNAME') ?? '',
      userRefreshToken: envValue('EBAY_USER_REFRESH_TOKEN'),
      userAccessToken: envValue('EBAY_USER_ACCESS_TOKEN') ?? envValue('EBAY_USER_TOKEN'),
      appToken: envValue('EBAY_APP_ACCESS_TOKEN') ?? envValue('EBAY_APP_TOKEN'),
    },
    cj: {
      openApiBase: envValue('CJ_OPEN_API_BASE') ?? 'https://developers.cjdropshipping.com',
      accessToken: envValue('CJ_ACCESS_TOKEN') ?? envValue('CJ_API_KEY'),
      email: envValue('CJ_EMAIL'),
      password: envValue('CJ_PASSWORD'),
      apiKey: envValue('CJ_API_KEY'),
    },
    ai: {
      provider: envValue('AI_PROVIDER') ?? 'none',
      apiKey: envValue('AI_API_KEY'),
      model: envValue('AI_MODEL'),
      openaiApiKey: envValue('OPENAI_API_KEY'),
      openaiModel: envValue('OPENAI_MODEL') ?? 'gpt-4o-mini',
      geminiApiKey: envValue('GEMINI_API_KEY') ?? envValue('GOOGLE_API_KEY'),
      geminiModel: envValue('GEMINI_MODEL') ?? 'gemini-1.5-flash',
    },
  };
}
