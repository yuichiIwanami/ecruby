'use strict';

var Product = function(id, name, price, qty) {
    this.id = id;
    this.name = name;
    this.price = price;
    this.qty = qty;
    this.tax = function() {
        return this.price*0.08;
    };
    this.subtotal = function() {
        return (Number(this.price)+Number(this.tax()))*this.qty;
    }
};

var ordersDetail = angular.module('ordersDetail', []).
    controller('ordersDetailCtrl', function($scope) {
        $scope.products = [
            new Product("001", "book", 1000, 1),
            new Product("002", "pen", 500, 1)
        ];

        $scope.discount = '';
        $scope.$watch('products', function() {
            var sumtotal = 0;
//            var grandtotal = 0;

            $scope.products.forEach(function(product) {
                sumtotal += product.subtotal();
            });
            $scope.sumtotal = sumtotal;

            $scope.grandtotal = function(){
                return sumtotal - $scope.discount;
            }

        }, true);
    });
