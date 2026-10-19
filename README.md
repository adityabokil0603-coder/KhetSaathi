# KhetSaathi — Farmer Support and Agricultural Information Management System

KhetSaathi is a **PHP and MySQL college mini-project** developed to demonstrate Database Management Systems (DBMS), Data Structures and Algorithms (DSA), and web development in an agricultural context.

The application provides a centralized interface for managing farmer information, field records, crop reference data, planting records, and support requests.

## Features

- **Farmer Management:** Maintain farmer records and contact information.
- **Field Management:** Associate fields with farmers and maintain field details, including soil type and fertility level.
- **Crop Catalogue:** Store and browse crop information and general growing-reference data.
- **Planting Records:** Maintain planting information associated with fields, crops, and seasons.
- **Support Request Management:** Record support requests, urgency levels, and request statuses.
- **Dashboard:** View summaries of application records.
- **Data Structures in Practice:** Demonstrate a support-request queue, a status-undo stack, a crop lookup hash table, and a session-only recent-activity linked list.

Crop information is intended for educational and general reference purposes. It is not personalized or authoritative agricultural advice.

## Technology Stack

| Component | Technology |
|---|---|
| Frontend | HTML, CSS, JavaScript |
| Backend | PHP 8.5 |
| Database | MySQL |
| Database Connectivity | PHP Data Objects (PDO) |
| Development Environment | Visual Studio Code |
| Execution Environment | Local PHP development server |

## System Architecture

KhetSaathi follows a client–server architecture.

**Browser (HTML, CSS, JavaScript) → PHP Backend → MySQL Database**

The browser provides the user interface, PHP processes application requests, and PDO connects the backend to the MySQL database.

## DBMS Concepts Demonstrated

The project provides practical exposure to the following concepts:

- Relational database design and table relationships
- Primary keys and foreign keys
- One-to-many and many-to-many relationships
- Junction tables for many-to-many associations
- Create, Read, Update, and Delete (CRUD) operations
- SQL queries, filtering, aggregation, and joins
- Data integrity and normalization principles
- Prepared statements using PDO

The database organizes farmer, field, crop, crop-guide, season, soil-type, planting, and support-request information into related tables.

## Data Structures and Algorithms

KhetSaathi connects data structures to application-level operations.

| Data Structure | Application |
|---|---|
| Queue | Organizing support requests in First In, First Out (FIFO) order |
| Stack | Tracking recent support-status changes for an undo operation |
| Hash Table | Crop lookup using hashing and separate chaining for collision handling |
| Linked List | Maintaining recent activity within the current session |

These implementations demonstrate how fundamental data structures can support practical application logic.

## Database Design

The application uses a relational database to organize information into dedicated tables for:

- Farmers
- Fields
- Crops
- Crop-growing reference information
- Seasons
- Crop–season associations
- Soil types
- Crop–soil suitability
- Planting records
- Support requests

Separating these entities helps organize related information and supports structured retrieval through relational queries.

## Security and Usage Limitations

- KhetSaathi is a **local, shared-workspace academic prototype**.
- The application does not require a login.
- It does not provide multi-user authentication or ownership-based access control.
- It is not designed or validated for public deployment or production use.
- Database credentials, private configuration files, and sensitive backups must remain outside the public repository.
- Crop reference information should not be treated as a substitute for advice from qualified agricultural experts.

## Project Objectives

The project aims to:

1. Apply DBMS concepts to a practical information-management application.
2. Understand relational database design, keys, and relationships.
3. Demonstrate SQL-based data retrieval and record management.
4. Apply fundamental data structures to application-level operations.
5. Understand communication between a browser, backend, and relational database.
6. Gain practical experience in developing and testing a web application.

## Future Scope

Potential improvements include:

- Implementing authentication and appropriate authorization.
- Reviewing and simplifying the legacy database ownership schema through a planned migration.
- Expanding automated testing and error handling.
- Improving accessibility and user-interface responsiveness.
- Enhancing database documentation and maintainability.

Future changes should be implemented and tested carefully before the application is used beyond its intended local academic environment.

## Conclusion

KhetSaathi brings together web development, relational database management, and data structures in an agricultural information-management project. It provides practical experience with PHP, MySQL, PDO, database relationships, application logic, and fundamental DSA concepts.
