import LookupManager from './LookupManager';

const CONFIG = {
  key: 'categories',
  label: 'Categories',
  singular: 'Category',
  listEndpoint: '/categories',
  createEndpoint: '/admin/categories',
  updateEndpoint: (id) => `/admin/categories/${id}`,
  nameField: 'category_name',
  codeField: 'category_code',
  nameLabel: 'Category name',
  codeLabel: 'Category code',
};

export default function ManageCategories() {
  return <LookupManager config={CONFIG} />;
}
