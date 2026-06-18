import { getConfig } from '@/backend/config/env';
import { CJ_API_VERSION_PREFIX, CJ_ENDPOINTS } from '@/backend/integrations/cj/endpoints';
import { httpRequest } from '@/backend/utils/http';
import type { CjProductSearchFilters, FreightQuote, IntegrationHealth } from '@/shared/types';

interface CjApiResponse<T = unknown> {
  code: number;
  result?: boolean;
  success?: boolean;
  message: string;
  data: T;
  requestId?: string;
}

function isApiSuccess(response: CjApiResponse): boolean {
  return response.result === true || response.success === true || response.code === 200 || response.code === 0;
}

function cleanParams(params: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== '' && !(Array.isArray(value) && value.length === 0))
  );
}

export class CjClient {
  private readonly baseUrl = getConfig().cj.openApiBase;
  private accessToken = getConfig().cj.accessToken;

  private async request<T>(endpoint: string, options: { method?: string; params?: Record<string, unknown>; body?: Record<string, unknown>; skipAuth?: boolean } = {}): Promise<T> {
    const headers: Record<string, string> = { 'Content-Type': 'application/json' };
    if (!options.skipAuth && this.accessToken) headers['CJ-Access-Token'] = this.accessToken;

    const response = await httpRequest<CjApiResponse<T>>({
      method: options.method ?? 'POST',
      baseUrl: this.baseUrl,
      url: `${CJ_API_VERSION_PREFIX}${endpoint}`,
      params: options.params,
      body: options.body,
      headers,
      timeoutMs: 30000,
    });

    if (!isApiSuccess(response.data)) {
      throw new Error(`CJ API error (${response.data.code}): ${response.data.message}`);
    }

    return response.data.data;
  }

  async healthCheck(): Promise<IntegrationHealth> {
    try {
      await this.getWarehouses();
      return {
        provider: 'cj',
        status: 'connected',
        environment: 'production',
        checkedAt: new Date().toISOString(),
        message: 'CJ credentials can read warehouse data.',
        canRead: true,
        canWrite: Boolean(this.accessToken),
      };
    } catch (error) {
      return {
        provider: 'cj',
        status: this.accessToken ? 'error' : 'not_connected',
        environment: 'production',
        checkedAt: new Date().toISOString(),
        message: error instanceof Error ? error.message : 'CJ health check failed.',
        canRead: false,
        canWrite: false,
      };
    }
  }

  async searchProducts(filters: CjProductSearchFilters): Promise<unknown> {
    const params = cleanParams({
      page: filters.pageNum ?? 1,
      size: Math.min(filters.pageSize ?? 20, 100),
      pageNum: filters.pageNum ?? 1,
      pageSize: Math.min(filters.pageSize ?? 20, 100),
      keyWord: filters.keyword,
      categoryId: filters.categoryId,
      countryCode: filters.countryCode,
      isWarehouse: filters.isWarehouse,
      startSellPrice: filters.minPrice,
      endSellPrice: filters.maxPrice,
      startWarehouseInventory: Math.max(filters.minInventory ?? 1, 1),
      endWarehouseInventory: filters.maxInventory,
      zonePlatform: filters.zonePlatform,
      platform: filters.zonePlatform,
      productFlag: filters.productFlag,
      addMarkStatus: filters.addMarkStatus,
      orderBy: filters.orderBy,
      sort: filters.sort,
      features: filters.features?.join(','),
    });

    return this.request(CJ_ENDPOINTS.product.listV2, { method: 'GET', params });
  }

  async getProductDetail(args: { pid?: string; productSku?: string; variantSku?: string; countryCode?: string }): Promise<unknown> {
    return this.request(CJ_ENDPOINTS.product.query, { method: 'GET', params: cleanParams({ ...args, features: 'enable_combine,enable_video,enable_description,enable_category' }) });
  }

  async getProductVariants(args: { pid?: string; productSku?: string; variantSku?: string; countryCode?: string }): Promise<unknown> {
    return this.request(CJ_ENDPOINTS.product.variantQuery, { method: 'GET', params: args });
  }

  async queryInventory(args: { vid?: string; sku?: string; pid?: string }): Promise<unknown> {
    if (args.vid) return this.request(CJ_ENDPOINTS.product.stockQueryByVid, { method: 'GET', params: { vid: args.vid } });
    if (args.sku) return this.request(CJ_ENDPOINTS.product.stockQueryBySku, { method: 'GET', params: { sku: args.sku } });
    return this.request(CJ_ENDPOINTS.product.stockGetInventoryByPid, { method: 'GET', params: { pid: args.pid } });
  }

  async calculateFreight(quote: FreightQuote): Promise<unknown> {
    return this.request(CJ_ENDPOINTS.logistic.freightCalculate, {
      body: {
        startCountryCode: quote.sourceCountry,
        endCountryCode: quote.destinationCountry,
        products: [{ pid: quote.productId, vid: quote.variantId, quantity: 1 }],
      },
    });
  }

  async getCategoryTree(parentId?: string): Promise<unknown> {
    return this.request(CJ_ENDPOINTS.product.getCategory, { method: 'GET', params: { parentId } });
  }

  async getWarehouses(): Promise<unknown> {
    return this.request(CJ_ENDPOINTS.product.globalWarehouseList, { method: 'GET' });
  }

  async listProductConnections(args: { shopId?: string; platformProductId?: string; platformVariantId?: string; page?: number; pageSize?: number }): Promise<unknown> {
    return this.request(CJ_ENDPOINTS.product.connList, { method: 'GET', params: { ...args, page: args.page ?? 1, pageSize: args.pageSize ?? 10 } });
  }

  async createProductConnection(body: Record<string, unknown>): Promise<unknown> {
    return this.request(CJ_ENDPOINTS.product.connList, { method: 'POST', body });
  }

  async getProductReviews(args: { pid: string; score?: number; pageNum?: number; pageSize?: number }): Promise<unknown> {
    return this.request(CJ_ENDPOINTS.product.productComments, { method: 'GET', params: { ...args, pageNum: args.pageNum ?? 1, pageSize: args.pageSize ?? 20 } });
  }
}
