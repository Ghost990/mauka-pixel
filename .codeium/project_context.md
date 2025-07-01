# Project Context: Vodafone Journey Catalog

## Technology Stack

Mauka Meta Pixel - WordPress Plugin

## Core Information

| Component | Details |
|-----------|---------|
| Project Type | WordPress Plugin |
| PHP Version | 7.4+ |
| WordPress Compatibility | 5.0 - 6.5 |
| WooCommerce Compatibility | 5.0 - 9.0 |
| Current Version | 1.0.1 |

## Technologies & Architecture

- **Language:** PHP 7.4+
- **Framework:** WordPress Plugin Architecture
- **Design Pattern:** Object-oriented with Singleton pattern
- **Code Organization:** Main plugin class with specialized helper and tracking classes
- **Integration:** WooCommerce and Meta (Facebook) APIs
- **Data Storage:** WordPress Options API

## Main Features

- Meta Pixel integration (standard browser pixel)
- Server-side Conversion API (CAPI) implementation
- Event deduplication between browser and server-side events
- Advanced WooCommerce tracking
- Customizable event parameters
- Enhanced eCommerce tracking for:
  - PageView
  - ViewContent
  - AddToCart
  - InitiateCheckout
  - Purchase
  - Lead
  - CompleteRegistration
  - Search
  - ViewCategory
  - AddToWishlist
  - AddPaymentInfo

## Code Structure

- `mauka-meta-pixel.php`: Main plugin file and initialization
- `includes/`:
  - `tracking-events.php`: Event tracking implementation
  - `helpers.php`: Utility functions and API handlers

## Development Guidelines

- Follow WordPress coding standards
- Use proper action and filter hooks for extending functionality
- Maintain backward compatibility with WordPress 5.0+
- Ensure WooCommerce compatibility through proper hook usage
- Implement proper nonce verification and permission checks for admin actions
- Use WordPress transient API for caching when appropriate

## Security Considerations

- ABSPATH checks to prevent direct file access
- Nonce verification for form submissions
- Input sanitization and validation
- Proper capability checks for administrative actions
- Secure API key storage

## Testing Requirements

- Test with both standard pixel and server-side CAPI enabled/disabled
- Verify event tracking across all supported WooCommerce actions
- Ensure proper data formatting for Meta Pixel specifications
- Test deduplication functionality between client and server events

## Performance Considerations

- Minimize database queries
- Optimize JavaScript loading
- Implement proper caching for API responses
- Consider high-traffic WooCommerce stores when implementing tracking
