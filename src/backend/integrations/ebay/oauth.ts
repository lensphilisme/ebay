import { getConfig } from '@/backend/config/env';
import { httpRequest } from '@/backend/utils/http';

export class EbayOAuthClient {
  private appAccessToken?: string;
  private appAccessTokenExpiry = 0;
  private userAccessToken?: string;
  private userAccessTokenExpiry = 0;

  getAuthorizationUrl(state: string): string {
    const config = getConfig().ebay;
    const scope = [
      'https://api.ebay.com/oauth/api_scope',
      'https://api.ebay.com/oauth/api_scope/sell.inventory',
      'https://api.ebay.com/oauth/api_scope/sell.account',
      'https://api.ebay.com/oauth/api_scope/sell.marketing',
      'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
      'https://api.ebay.com/oauth/api_scope/sell.analytics.readonly',
    ].join(' ');
    const authBase = config.environment === 'sandbox' ? 'https://auth.sandbox.ebay.com/oauth2/authorize' : 'https://auth.ebay.com/oauth2/authorize';

    const params = new URLSearchParams({
      client_id: config.clientId,
      redirect_uri: config.ruName || config.redirectUri,
      response_type: 'code',
      scope,
      state,
    });

    return `${authBase}?${params.toString()}`;
  }

  async getAccessToken(): Promise<string> {
    const config = getConfig().ebay;
    if (this.userAccessToken && Date.now() < this.userAccessTokenExpiry) return this.userAccessToken;
    if (config.userRefreshToken) return this.refreshUserToken(config.userRefreshToken);
    if (config.userAccessToken) return config.userAccessToken;
    if (config.appToken) return config.appToken;
    if (this.appAccessToken && Date.now() < this.appAccessTokenExpiry) return this.appAccessToken;

    const credentials = Buffer.from(`${config.clientId}:${config.clientSecret}`).toString('base64');
    const response = await httpRequest<{ access_token: string; expires_in: number }>({
      method: 'POST',
      url: `${config.apiBaseUrl}/identity/v1/oauth2/token`,
      body: new URLSearchParams({
        grant_type: 'client_credentials',
        scope: 'https://api.ebay.com/oauth/api_scope',
      }),
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        Authorization: `Basic ${credentials}`,
      },
    });

    this.appAccessToken = response.data.access_token;
    this.appAccessTokenExpiry = Date.now() + response.data.expires_in * 1000 - 60000;
    return this.appAccessToken;
  }

  async refreshUserToken(refreshToken: string): Promise<string> {
    const config = getConfig().ebay;
    const credentials = Buffer.from(`${config.clientId}:${config.clientSecret}`).toString('base64');
    const response = await httpRequest<{ access_token: string; expires_in: number }>({
      method: 'POST',
      url: `${config.apiBaseUrl}/identity/v1/oauth2/token`,
      body: new URLSearchParams({
        grant_type: 'refresh_token',
        refresh_token: refreshToken,
      }),
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        Authorization: `Basic ${credentials}`,
      },
    });

    this.userAccessToken = response.data.access_token;
    this.userAccessTokenExpiry = Date.now() + response.data.expires_in * 1000 - 60000;
    return this.userAccessToken;
  }
}
