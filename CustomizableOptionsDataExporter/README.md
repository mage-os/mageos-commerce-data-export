# Magento_CustomizableOptionsDataExporter module

The `Magento_CustomizableOptionsDataExporter` module adds Adobe Commerce customizable options to the catalog product feed.

## Features

- Adds the `ac_customizable_options` product attribute to the `products` feed.
- Exports selectable options such as drop-down, radio button, checkbox, and multiple-select options.
- Exports shopper-input options such as text field, text area, file, and date options.
- Reuses the option providers from `Magento_CatalogDataExporter`, preserving their store-view resolution, prices, and canonical option UIDs.
- Registers the attribute as an opaque JSON `OBJECT` attribute, so consumers can process the payload without a catalog schema change.

## Exported attribute

The attribute is added only when the product has customizable options. Its value is a serialized JSON object with the following structure:

```json
{
	"schemaVersion": 1,
	"selectable": [
		{
			"id": "...",
			"values": [
				{
					"id": "custom-option/..."
				}
			]
		}
	],
	"shopperInput": [
		{
			"id": "..."
		}
	]
}
```

The `selectable` collection mirrors the `optionsV2` product feed field, while `shopperInput` mirrors `shopperInputOptions`. Selectable value UIDs use the canonical `custom-option/...` format.
