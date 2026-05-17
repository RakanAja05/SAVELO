<?php

namespace App\Services;

use Kreait\Firebase\Auth;
use Kreait\Firebase\Database;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Firestore;

class FirebaseClient
{
    public function __construct(private readonly Factory $factory) {}

    public function auth(): Auth
    {
        return $this->factory->createAuth();
    }

    public function database(): Database
    {
        return $this->factory->createDatabase();
    }

    public function firestore(): Firestore
    {
        return $this->factory->createFirestore();
    }
}
