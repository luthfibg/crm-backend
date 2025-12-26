<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create minimal tables required for tests to avoid running full migrations
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('role', ['administrator', 'sales', 'presales', 'telesales'])->default('sales');
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('kpi_id')->nullable();
            $table->string('name')->nullable();
            $table->string('institution')->nullable();
            $table->string('phone_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function test_admin_sees_user_id_and_kpi_id()
    {
        // Create admin user
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password', 'role' => 'administrator']);

        // Create a customer attached to another user and with kpi_id
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'role' => 'sales']);
        $customer = Customer::create([
            'user_id' => $owner->id,
            'kpi_id' => 123,
            'name' => 'ACME',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/customers/' . $customer->id);

        $response->assertStatus(200)
                 ->assertJsonFragment([
                     'user_id' => $customer->user_id,
                     'kpi_id' => $customer->kpi_id,
                 ]);
    }

    public function test_sales_does_not_see_user_id_and_kpi_id_when_fetching_customer()
    {
        // Create two sales users
        $sales = User::create(['name' => 'Sales', 'email' => 'sales@example.com', 'password' => 'password', 'role' => 'sales']);
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password', 'role' => 'sales']);

        // Customer owned by $other
        $customer = Customer::create(['user_id' => $other->id, 'kpi_id' => 999, 'name' => 'Client']);

        Sanctum::actingAs($sales);

        // Sales who doesn't own the customer should get 403 (forbidden)
        $response = $this->getJson('/api/customers/' . $customer->id);
        $response->assertStatus(403);

        // Sales owning the customer can view it but should not see user_id/kpi_id
        Sanctum::actingAs($other);
        $response2 = $this->getJson('/api/customers/' . $customer->id);

        $response2->assertStatus(200)
                  ->assertJsonMissing(['user_id' => $customer->user_id])
                  ->assertJsonMissing(['kpi_id' => $customer->kpi_id]);
    }

    public function test_index_shows_user_id_kpi_id_only_for_admins()
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin2@example.com', 'password' => 'password', 'role' => 'administrator']);
        $sales = User::create(['name' => 'Sales', 'email' => 'sales2@example.com', 'password' => 'password', 'role' => 'sales']);

        // Create few customers
        Customer::create(['user_id' => $sales->id, 'kpi_id' => 1, 'name' => 'A']);
        Customer::create(['user_id' => $sales->id, 'kpi_id' => 1, 'name' => 'B']);

        Sanctum::actingAs($admin);
        $adminResp = $this->getJson('/api/customers');
        $adminResp->assertStatus(200)
                  ->assertJsonFragment(['user_id' => $sales->id]);

        Sanctum::actingAs($sales);
        $salesResp = $this->getJson('/api/customers');
        $salesResp->assertStatus(200)
                 ->assertJsonMissing(['user_id' => $sales->id])
                 ->assertJsonMissing(['kpi_id' => 1]);
    }
}
