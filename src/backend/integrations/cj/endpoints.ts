export const CJ_API_VERSION_PREFIX = '/api2.0/v1';

export const CJ_ENDPOINTS = {
  auth: {
    getAccessToken: '/authentication/getAccessToken',
    refreshAccessToken: '/authentication/refreshAccessToken',
    getAuthorizeUrl: '/authentication/getAuthorizeUrl',
  },
  product: {
    query: '/product/query',
    listV2: '/product/listV2',
    getCategory: '/product/getCategory',
    globalWarehouseList: '/product/globalWarehouseList',
    variantQuery: '/product/variant/query',
    stockQueryByVid: '/product/stock/queryByVid',
    stockQueryBySku: '/product/stock/queryBySku',
    stockGetInventoryByPid: '/product/stock/getInventoryByPid',
    connList: '/product/conn/connection',
    productComments: '/product/productComments',
  },
  logistic: {
    freightCalculate: '/logistic/freightCalculate',
  },
} as const;
