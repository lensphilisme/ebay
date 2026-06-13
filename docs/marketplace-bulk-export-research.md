# Marketplace Bulk Export Research

Date: 2026-06-13

This note records the official resources and local templates used for the CJ to marketplace bulk export layer.

## Official Resources

- eBay Seller Hub Feed flow: https://developer.ebay.com/api-docs/sell/static/feed/fx-feeds-overview.html
  - Seller Hub feed files use task creation, upload, status polling, and result download.
  - eBay states templates are configured/downloaded from Seller Hub Reports resources.
  - Seller Hub listing feed uploads accept CSV files.
- eBay LMS Feed flow: https://developer.ebay.com/api-docs/sell/static/feed/lms-feeds-working-with-lms.html
  - Trading API XML feeds can bulk add, revise, relist, verify, or end listings.
- eBay Taxonomy API:
  - Category suggestions: https://developer.ebay.com/api-docs/commerce/taxonomy/resources/category_tree/methods/getCategorySuggestions
  - Full category tree: https://developer.ebay.com/api-docs/commerce/taxonomy/resources/category_tree/methods/getCategoryTree
  - Item aspects: https://developer.ebay.com/api-docs/commerce/taxonomy/resources/category_tree/methods/getItemAspectsForCategory
- Facebook Marketplace spreadsheet upload: https://www.facebook.com/help/1943158472539049
- Meta catalog fields: https://developers.facebook.com/documentation/ads-commerce/commerce-platform/catalog/fields
- Meta product categories: https://developers.facebook.com/documentation/ads-commerce/catalog/guides/product-categories
- Google product taxonomy for Meta `google_product_category`: https://support.google.com/merchants/answer/6324436
- Google taxonomy text list: https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt
- TikTok Shop bulk listing: https://seller-us.tiktok.com/university/essay?knowledge_id=428445525411626
- TikTok Shop Product API:
  - Create Product: https://partner.tiktokshop.com/docv2/page/6502fc8da57708028b42b18a
  - Get Category Rules: https://partner.tiktokshop.com/docv2/page/get-category-rules-202309
  - Get Attributes: https://partner.tiktokshop.com/docv2/page/get-attributes-202309

## Local Templates Inspected

- `Marketplace_Bulk_Upload_Template.xlsx`
  - Sheet: `Bulk Upload Template`
  - Columns: `TITLE`, `PRICE`, `CONDITION`, `DESCRIPTION`, `CATEGORY`, `SHIPPING WEIGHT`, `OFFER FREE SHIPPING`, `OFFER SHIPPING`
  - Validation sheet contains 1,870 Facebook Marketplace category paths.
- `eBay-category-listing-template-Jun-13-2026-12-35-9.csv`
  - 108 columns.
  - Required columns include action, category, title, condition, required category aspects, description, format, duration, start price, quantity, location, dispatch time, and returns.
- `eBay-prefill-listing-input-template-Jun-13-2026-12-52-10.xlsx`
  - Input columns: `Custom Label (SKU)`, `Item Photo URL`, `Title`, `Category`, `Aspects`.
- `eBay-prefill-listing-recommendations-Jun-2026-13-09-53-52-11313342214.xlsm`
  - Contains category tabs, category ID `116022`, and required/preferred aspects for the generated category.
- `Tiktoksellercenter_Home Supplies_20260613_..._template.xlsx`
  - Sheet: `Template`
  - Machine headers include `category`, `brand`, `product_name`, `product_description`, `main_image`, `image_2` through `image_9`, variation fields, parcel dimensions, delivery, price, warehouse quantity columns, seller SKU, and `product_property/*` attribute columns.
  - Data validation starts at row 7; generated XLSX exports inject rows there to preserve template validation.

## Implementation Notes

- eBay and TikTok categories/templates are marketplace, seller, region, and category dependent. The exporter reads downloaded official templates instead of hard-coding a stale universal template.
- Facebook Marketplace bulk upload supports only the 8 workbook columns above. The exporter also creates a Meta catalog CSV mirror so images, SKU, variants, inventory, and Google product categories are not lost.
- TikTok documentation says to use the TikTok Shop Excel template. The exporter scans `TIKTOK_SHOP_TEMPLATE_DIR` for Seller Center templates, reads the categories inside each workbook, auto-selects the closest template per CJ product, and splits TikTok exports into multiple template-based XLSX/CSV files when selected products belong to different TikTok template groups. `TIKTOK_SHOP_TEMPLATE_PATH` is only a fallback.
